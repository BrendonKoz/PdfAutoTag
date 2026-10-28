<?php namespace ProcessWire;

/**
 * Adobe PDF Accessibility Auto-Tag REST client.
 *
 * Uses WireHttp for token, asset, job, status, and download calls.
 */
class AdobeAutoTagClient extends Wire {

	const API_BASE = 'https://pdf-services.adobe.io';
	const TOKEN_CACHE = 'PdfAutoTag_token';

	protected $clientId = '';
	protected $clientSecret = '';
	protected $token = '';
	protected $tokenExpires = 0;

	/** @var PdfAutoTag|null */
	protected $usage = null;

	public function __construct($clientId, $clientSecret) {
		parent::__construct();
		$this->clientId = (string) $clientId;
		$this->clientSecret = (string) $clientSecret;
	}

	/**
	 * Module that counts Adobe API calls against the monthly limit.
	 *
	 * @param PdfAutoTag $module
	 */
	public function setUsage(PdfAutoTag $module) {
		$this->usage = $module;
	}

	/**
	 * Adobe calls needed to check one job that already has a status URL.
	 * A saved token that is still inside expires_in skips the token request.
	 *
	 * @return int
	 */
	public function callsToPoll() {
		return $this->tokenIsCurrent() ? 1 : 2;
	}

	/**
	 * Adobe calls needed to submit one PDF in this run: token, asset, and auto-tag.
	 * A saved token that is still inside expires_in skips the token request.
	 * The upload and later status checks are separate.
	 *
	 * @return int
	 */
	public function callsToStart() {
		return $this->tokenIsCurrent() ? 2 : 3;
	}

	/**
	 * True when this client holds an access token that is still inside its lifetime.
	 *
	 * @return bool
	 */
	public function hasToken() {
		return $this->tokenIsCurrent();
	}

	/**
	 * Confirm the client id and secret can obtain an access token.
	 *
	 * @return bool
	 * @throws WireException
	 */
	public function testCredentials() {
		$this->token = '';
		$this->tokenExpires = 0;
		$this->accessToken(true);
		return true;
	}

	/**
	 * Upload a PDF and start an auto-tag job.
	 *
	 * @param string $filePath
	 * @param bool $generateReport
	 * @param bool $shiftHeadings
	 * @return string Status URL
	 * @throws WireException
	 */
	public function startJob($filePath, $generateReport, $shiftHeadings) {
		if(!is_file($filePath)) {
			throw new WireException('PDF file was not found.');
		}
		$asset = $this->createAsset();
		$this->uploadAsset($asset['uploadUri'], $filePath);
		return $this->createJob($asset['assetID'], $generateReport, $shiftHeadings);
	}

	/**
	 * Poll a job status URL once.
	 *
	 * @param string $location
	 * @return array status, taggedUrl, reportUrl, error, retry
	 * @throws WireException
	 */
	public function poll($location) {
		$http = $this->http();
		$this->authorize($http);
		$this->beginAdobe($location);
		$body = $http->get($location);
		$code = (int) $http->getHttpCode();
		$this->tokenRejected($code);
		if($body === false || $code === 429) {
			return array(
				'status' => 'retry',
				'taggedUrl' => '',
				'reportUrl' => '',
				'error' => $this->httpError($http, $body, 'Adobe rate limit reached. The job will be tried again.'),
				'retry' => true,
			);
		}
		if($code >= 400) {
			throw new WireException($this->httpError($http, $body, 'Adobe status request failed.'));
		}
		$data = $this->decode($body);
		$status = isset($data['status']) ? strtolower((string) $data['status']) : '';
		if($status === 'in progress' || $status === 'in_progress') {
			return array(
				'status' => 'in progress',
				'taggedUrl' => '',
				'reportUrl' => '',
				'error' => '',
				'retry' => false,
			);
		}
		if($status === 'failed') {
			throw new WireException($this->adobeErrorMessage($data, 'Adobe auto-tag failed.'));
		}
		if($status !== 'done') {
			throw new WireException('Unexpected Adobe status: ' . ($status !== '' ? $status : 'empty'));
		}
		$tagged = '';
		$report = '';
		if(isset($data['tagged-pdf']['downloadUri'])) $tagged = (string) $data['tagged-pdf']['downloadUri'];
		if(isset($data['report']['downloadUri'])) $report = (string) $data['report']['downloadUri'];
		if($tagged === '') {
			throw new WireException('Adobe finished without a tagged PDF download.');
		}
		return array(
			'status' => 'done',
			'taggedUrl' => $tagged,
			'reportUrl' => $report,
			'error' => '',
			'retry' => false,
		);
	}

	/**
	 * Download a URL to a local path.
	 *
	 * @param string $url
	 * @param string $dest
	 * @throws WireException
	 */
	public function download($url, $dest) {
		$dir = dirname($dest);
		if(!is_dir($dir)) {
			throw new WireException('Download directory is missing.');
		}
		$this->beginAdobe($url);
		$http = $this->http();
		$http->setTimeout(180);
		try {
			$http->download($url, $dest);
		} catch(\Exception $e) {
			if(is_file($dest)) @unlink($dest);
			if((int) $e->getCode() === 402) throw $e;
			throw new WireException($e->getMessage());
		}
		if(!is_file($dest) || !filesize($dest)) {
			if(is_file($dest)) @unlink($dest);
			throw new WireException('Downloaded file was empty.');
		}
	}

	protected function accessToken($force = false) {
		if(!$force) {
			if(!$this->tokenIsCurrent()) $this->restoreToken();
			if($this->tokenIsCurrent()) return $this->token;
		}
		if($this->clientId === '' || $this->clientSecret === '') {
			throw new WireException('Adobe client id and client secret are not configured.');
		}
		$this->beginAdobe(self::API_BASE . '/token');
		$http = $this->http();
		$body = $http->post(self::API_BASE . '/token', array(
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
		));
		$code = (int) $http->getHttpCode();
		if($body === false || $code >= 400) {
			throw new WireException($this->httpError($http, $body, 'Adobe rejected the client credentials.'));
		}
		$data = $this->decode($body);
		if(empty($data['access_token'])) {
			throw new WireException('Adobe did not return an access token.');
		}
		$this->token = (string) $data['access_token'];
		$expires = isset($data['expires_in']) ? (int) $data['expires_in'] : 300;
		if($expires < 1) $expires = 300;
		$this->tokenExpires = time() + $expires;
		$this->storeToken();
		return $this->token;
	}

	protected function createAsset() {
		$http = $this->http();
		$this->authorize($http);
		$this->beginAdobe(self::API_BASE . '/assets');
		$http->setHeader('content-type', 'application/json');
		$body = $http->post(self::API_BASE . '/assets', json_encode(array(
			'mediaType' => 'application/pdf',
		)));
		$code = (int) $http->getHttpCode();
		$this->tokenRejected($code);
		if($body === false || $code === 429) {
			throw new WireException($this->httpError($http, $body, 'Adobe rate limit reached while creating an upload.'), 429);
		}
		if($code >= 400) {
			throw new WireException($this->httpError($http, $body, 'Adobe did not accept the upload request.'));
		}
		$data = $this->decode($body);
		if(empty($data['uploadUri']) || empty($data['assetID'])) {
			throw new WireException('Adobe did not return an upload URL.');
		}
		return array(
			'uploadUri' => (string) $data['uploadUri'],
			'assetID' => (string) $data['assetID'],
		);
	}

	protected function uploadAsset($uploadUri, $filePath) {
		$bytes = file_get_contents($filePath);
		if($bytes === false) {
			throw new WireException('The PDF could not be read.');
		}
		$this->beginAdobe($uploadUri);
		$http = $this->http();
		$http->setTimeout(180);
		$http->setHeader('content-type', 'application/pdf');
		$result = $http->put($uploadUri, $bytes);
		$code = (int) $http->getHttpCode();
		if($result === false || $code >= 400) {
			throw new WireException($this->httpError($http, $result, 'Adobe did not accept the PDF upload.'));
		}
	}

	protected function createJob($assetId, $generateReport, $shiftHeadings) {
		$http = $this->http();
		$this->authorize($http);
		$this->beginAdobe(self::API_BASE . '/operation/autotag');
		$http->setHeader('content-type', 'application/json');
		$options = array();
		if(defined('CURLOPT_FOLLOWLOCATION')) {
			$options['curl'] = array(
				'setopt' => array(
					CURLOPT_FOLLOWLOCATION => false,
				),
			);
		}
		$body = $http->post(self::API_BASE . '/operation/autotag', json_encode(array(
			'assetID' => $assetId,
			'generateReport' => $generateReport ? true : false,
			'shiftHeadings' => $shiftHeadings ? true : false,
		)), $options);
		$code = (int) $http->getHttpCode();
		$this->tokenRejected($code);
		if($body === false && $code === 0) {
			throw new WireException($this->httpError($http, $body, 'Adobe auto-tag request failed.'));
		}
		if($code === 429) {
			throw new WireException($this->httpError($http, $body, 'Adobe rate limit reached while starting auto-tag.'), 429);
		}
		if($code >= 400) {
			throw new WireException($this->httpError($http, $body, 'Adobe rejected the auto-tag job.'));
		}
		$location = (string) $http->getResponseHeader('location');
		if($location === '') {
			throw new WireException('Adobe did not return a job status URL.');
		}
		if(strpos($location, 'http') !== 0) {
			$location = self::API_BASE . '/' . ltrim($location, '/');
		}
		return $location;
	}

	protected function tokenIsCurrent() {
		return $this->token !== '' && $this->tokenExpires > (time() + 60);
	}

	/**
	 * Load a token saved from an earlier queue run, when Adobe's expires_in time is still in the future.
	 */
	public function restoreToken() {
		if($this->tokenIsCurrent()) return;
		$cache = $this->wire('cache');
		if(!$cache) return;
		$cached = $cache->get(self::TOKEN_CACHE);
		if(!is_array($cached)) return;
		$token = isset($cached['token']) ? (string) $cached['token'] : '';
		$expires = isset($cached['expires']) ? (int) $cached['expires'] : 0;
		$fingerprint = isset($cached['fingerprint']) && is_string($cached['fingerprint']) ? $cached['fingerprint'] : '';
		$expected = $this->credentialFingerprint();
		if($token === '' || $expires <= (time() + 60) || strlen($fingerprint) !== strlen($expected) || !hash_equals($expected, $fingerprint)) {
			$cache->delete(self::TOKEN_CACHE);
			return;
		}
		$this->token = $token;
		$this->tokenExpires = $expires;
	}

	/**
	 * Remember the token until one minute before the lifetime Adobe sent in expires_in.
	 */
	protected function storeToken() {
		$cache = $this->wire('cache');
		if(!$cache || !$this->tokenIsCurrent()) return;
		$ttl = $this->tokenExpires - time();
		if($ttl < 61) return;
		$cache->save(self::TOKEN_CACHE, array(
			'token' => $this->token,
			'expires' => $this->tokenExpires,
			'fingerprint' => $this->credentialFingerprint(),
		), $ttl);
	}

	/**
	 * Drop a token Adobe has rejected so the next run requests a new one.
	 */
	public function discardToken() {
		$this->token = '';
		$this->tokenExpires = 0;
		$cache = $this->wire('cache');
		if($cache) $cache->delete(self::TOKEN_CACHE);
	}

	/**
	 * @param int $code
	 * @throws WireException code 401 when the saved token was rejected
	 */
	protected function tokenRejected($code) {
		if((int) $code !== 401) return;
		$this->discardToken();
		throw new WireException('Adobe rejected the saved access token. A new token will be requested on the next queue run.', 401);
	}

	/**
	 * Ties a saved token to the current client id and secret without storing the secret in the cache name.
	 *
	 * @return string
	 */
	protected function credentialFingerprint() {
		return hash('sha256', $this->clientId . "\0" . $this->clientSecret);
	}

	/**
	 * Count one call to pdf-services.adobe.io, or stop when this month's limit is already used.
	 * Storage URLs outside that host are not counted.
	 *
	 * @param string $url
	 * @throws WireException code 402 when the monthly limit blocks the call
	 */
	protected function beginAdobe($url) {
		if(strpos((string) $url, self::API_BASE) !== 0) return;
		if($this->usage && !$this->usage->canSpendApiCalls(1)) {
			throw new WireException('The monthly Adobe API call limit has been reached. This PDF stays queued until the limit resets next month.', 402);
		}
		if($this->usage) $this->usage->recordApiCall();
	}

	protected function authorize(WireHttp $http) {
		$http->setHeader('authorization', 'Bearer ' . $this->accessToken());
		$http->setHeader('x-api-key', $this->clientId);
		return $http;
	}

	protected function http() {
		$http = new WireHttp();
		$this->wire($http);
		$http->setTimeout(60);
		$http->setValidateURLOptions(array(
			'maxLength' => 16384,
			'stripQuotes' => false,
		));
		return $http;
	}

	protected function decode($body) {
		if(!is_string($body) || $body === '') return array();
		$data = json_decode($body, true);
		return is_array($data) ? $data : array();
	}

	protected function httpError(WireHttp $http, $body, $fallback) {
		$message = $this->adobeErrorMessage($this->decode($body), '');
		if($message !== '') return $message;
		$error = $http->getError();
		if(is_string($error) && $error !== '') return $error;
		$code = (int) $http->getHttpCode();
		if($code) return $fallback . ' (HTTP ' . $code . ')';
		return $fallback;
	}

	protected function adobeErrorMessage(array $data, $fallback) {
		if(isset($data['error']) && is_array($data['error'])) {
			$code = isset($data['error']['code']) ? (string) $data['error']['code'] : '';
			$message = isset($data['error']['message']) ? (string) $data['error']['message'] : '';
			if($code !== '' && $message !== '') return $code . ': ' . $message;
			if($message !== '') return $message;
			if($code !== '') return $code;
		}
		return $fallback;
	}
}
