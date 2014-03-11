<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 */

namespace Nette\Application;

use Nette;


/**
 * Front Controller.
 *
 * @author     David Grudl
 *
 * @property-read array $requests
 * @property-read IPresenter $presenter
 * @property-read IRouter $router
 * @property-read IPresenterFactory $presenterFactory
 */
class Application extends Nette\Object
{
	/** @internal special parameter key */
	const REQUEST_KEY = '_rid';

	/** @var int */
	public static $maxLoop = 20;

	/** @var bool enable fault barrier? */
	public $catchExceptions;

	/** @var string */
	public $errorPresenter;

	/** @var array of function(Application $sender); Occurs before the application loads presenter */
	public $onStartup;

	/** @var array of function(Application $sender, \Exception $e = NULL); Occurs before the application shuts down */
	public $onShutdown;

	/** @var array of function(Application $sender, Request $request); Occurs when a new request is received */
	public $onRequest;

	/** @var array of function(Application $sender, Presenter $presenter); Occurs when a presenter is created */
	public $onPresenter;

	/** @var array of function(Application $sender, IResponse $response); Occurs when a new response is ready for dispatch */
	public $onResponse;

	/** @var array of function(Application $sender, \Exception $e); Occurs when an unhandled exception occurs in the application */
	public $onError;

	/** @var Request[] */
	private $requests = array();

	/** @var IPresenter */
	private $presenter;

	/** @var Nette\Http\IRequest */
	private $httpRequest;

	/** @var Nette\Http\IResponse */
	private $httpResponse;

	/** @var IPresenterFactory */
	private $presenterFactory;

	/** @var IRouter */
	private $router;

	/** @var Nette\Http\Session */
	private $session;

	/** @var Nette\Security\User */
	private $user;


	public function __construct(IPresenterFactory $presenterFactory, IRouter $router, Nette\Http\IRequest $httpRequest, Nette\Http\IResponse $httpResponse, Nette\Http\Session $session, Nette\Security\User $user)
	{
		$this->httpRequest = $httpRequest;
		$this->httpResponse = $httpResponse;
		$this->presenterFactory = $presenterFactory;
		$this->router = $router;
		$this->session = $session;
		$this->user = $user;
	}


	/**
	 * Dispatch a HTTP request to a front controller.
	 * @return void
	 */
	public function run()
	{
		try {
			$this->onStartup($this);
			$this->processRequest($this->createInitialRequest());
			$this->onShutdown($this);

		} catch (\Exception $e) {
			$this->onError($this, $e);
			if ($this->catchExceptions && $this->errorPresenter) {
				try {
					$this->processException($e);
					$this->onShutdown($this, $e);
					return;

				} catch (\Exception $e) {
					$this->onError($this, $e);
				}
			}
			$this->onShutdown($this, $e);
			throw $e;
		}
	}


	/**
	 * @return Request
	 */
	public function createInitialRequest()
	{
		$request = $this->router->match($this->httpRequest);

		if (!$request instanceof Request) {
			throw new BadRequestException('No route for HTTP request.');

		} elseif (strcasecmp($request->getPresenterName(), $this->errorPresenter) === 0) {
			throw new BadRequestException('Invalid request. Presenter is not achievable.');
		}

		if ($rid = $this->httpRequest->getQuery(self::REQUEST_KEY)) {
			$storedRequest = $this->getStoredRequest($rid, $request);
			if ($storedRequest) {
				return $storedRequest;
			}
		}

		try {
			$name = $request->getPresenterName();
			$this->presenterFactory->getPresenterClass($name);
			$request->setPresenterName($name);
		} catch (InvalidPresenterException $e) {
			throw new BadRequestException($e->getMessage(), 0, $e);
		}

		return $request;
	}


	/**
	 * @return void
	 */
	public function processRequest(Request $request)
	{
		if (count($this->requests) > self::$maxLoop) {
			throw new ApplicationException('Too many loops detected in application life cycle.');
		}

		$this->requests[] = $request;
		$this->onRequest($this, $request);

		$this->presenter = $this->presenterFactory->createPresenter($request->getPresenterName());
		$this->onPresenter($this, $this->presenter);
		$response = $this->presenter->run($request);

		if ($response instanceof Responses\ForwardResponse) {
			$this->processRequest($response->getRequest());

		} elseif ($response) {
			$this->onResponse($this, $response);
			$response->send($this->httpRequest, $this->httpResponse);
		}
	}


	/**
	 * @return void
	 */
	public function processException(\Exception $e)
	{
		if (!$this->httpResponse->isSent()) {
			$this->httpResponse->setCode($e instanceof BadRequestException ? ($e->getCode() ?: 404) : 500);
		}

		$args = array('exception' => $e, 'request' => end($this->requests) ?: NULL);
		if ($this->presenter instanceof UI\Presenter) {
			try {
				$this->presenter->forward(":$this->errorPresenter:", $args);
			} catch (AbortException $foo) {
				$this->processRequest($this->presenter->getLastCreatedRequest());
			}
		} else {
			$this->processRequest(new Request($this->errorPresenter, Request::FORWARD, $args));
		}
	}


	/**
	 * Returns all processed requests.
	 * @return Request[]
	 */
	public function getRequests()
	{
		return $this->requests;
	}


	/**
	 * Returns current presenter.
	 * @return IPresenter
	 */
	public function getPresenter()
	{
		return $this->presenter;
	}


	/********************* request serialization ****************d*g**/


	/**
	 * Stores current request to session.
	 * @param  Request request
	 * @param  mixed  optional expiration time
	 * @return string key
	 */
	public function storeRequest(Request $request, $expiration = '+ 10 minutes')
	{
		$session = $this->session->getSection('Nette.Application/requests');
		do {
			$key = Nette\Utils\Random::generate(5);
		} while (isset($session[$key]));

		$session[$key] = array($this->user->getId(), $request);
		$session->setExpiration($expiration, $key);
		return $key;
	}


	/**
	 * Loads stored request from session.
	 * @param  string key
	 * @param  Request current request
	 * @return \Nette\Application\Request|NULL
	 */
	public function getStoredRequest($key, Request $currentRequest)
	{
		$session = $this->session->getSection('Nette.Application/requests');
		if (!isset($session[$key]) || ($session[$key][0] !== NULL && $session[$key][0] !== $this->user->getId())) {
			return NULL;
		}
		$request = clone $session[$key][1];

		$params = $request->getParameters();

		if ($request->getPresenterName() !== $currentRequest->getPresenterName()) {
			$params[self::REQUEST_KEY] = $key;
			$action = $params[Nette\Application\UI\Presenter::ACTION_KEY];
			unset($params[Nette\Application\UI\Presenter::ACTION_KEY]);
			$this->presenter->redirect(":$request->presenterName:$action", $params);
		}

		$request->setFlag(Request::RESTORED, TRUE);

		$currentParams = $currentRequest->getParameters();
		if (isset($currentParams[Nette\Application\UI\Presenter::FLASH_KEY])) {
			$params[Nette\Application\UI\Presenter::FLASH_KEY] = $currentParams[Nette\Application\UI\Presenter::FLASH_KEY];
		}
		$request->setParameters($params);

		return $request;
	}


	/********************* services ****************d*g**/


	/**
	 * Returns router.
	 * @return IRouter
	 */
	public function getRouter()
	{
		return $this->router;
	}


	/**
	 * Returns presenter factory.
	 * @return IPresenterFactory
	 */
	public function getPresenterFactory()
	{
		return $this->presenterFactory;
	}

}
