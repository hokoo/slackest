<?php

namespace iTRON\Slackest;

class Slackest {

	/** @var string */
	private $botToken;

	/** @var string */
	private $channelId;

	/** @var string|null */
	private $lastError;

	/** @var array|null */
	private $errorData;

	/**
	 * @param string $botToken  Slack Bot token (xoxb-...).
	 * @param string $channelId Slack channel ID (C..., G..., D...).
	 */
	public function __construct(string $botToken, string $channelId)
	{
		$this->botToken  = $botToken;
		$this->channelId = $channelId;
	}

	/**
	 * Send a message with an optional file attachment.
	 *
	 * @param string      $message
	 * @param string|null $filePath
	 *
	 * @return bool
	 */
	public function send(string $message, ?string $filePath = null): bool
	{
		$this->lastError = null;

		if ($filePath && is_readable($filePath)) {
			return $this->sendWithFile($message, $filePath);
		}

		return $this->sendTextOnly($message);
	}

	/**
	 * Returns last error message (if any).
	 *
	 * @return string|null
	 */
	public function getLastError(): ?string
	{
		return $this->lastError;
	}

	/**
	 * Returns last error data (if any).
	 *
	 * @return array|null
	 */
	public function getErrorData(): ?array
	{
		return $this->errorData;
	}

	/**
	 * Send plain text message via chat.postMessage.
	 *
	 * @param string $message
	 *
	 * @return bool
	 */
	private function sendTextOnly(string $message): bool
	{
		$url  = 'https://slack.com/api/chat.postMessage';
		$data = [
			'channel' => $this->channelId,
			'text'    => $message,
		];

		$response = $this->curlJsonPost($url, $data);
		if ($response === null) {
			return false;
		}

		if (empty($response['ok'])) {
			$this->lastError = 'Slack chat.postMessage error: ' . ($response['error'] ?? 'unknown');
			$this->errorData = $response;
			return false;
		}

		return true;
	}

	/**
	 * Send message with a file using external upload flow.
	 *
	 * @param string $message
	 * @param string $filePath
	 *
	 * @return bool
	 */
	private function sendWithFile(string $message, string $filePath): bool
	{
		$filename = basename($filePath);
		$filesize = filesize($filePath);

		if ($filesize === false) {
			$this->lastError = 'Unable to determine file size.';
			return false;
		}

		// 1) Request upload_url and file_id
		$uploadMeta = $this->getUploadUrlExternal($filename, $filesize);
		if ($uploadMeta === null) {
			$this->lastError = '';
			return false;
		}

		$uploadUrl = $uploadMeta['upload_url'];
		$fileId    = $uploadMeta['file_id'];

		// 2) Upload binary to upload_url
		if (!$this->uploadBinary($uploadUrl, $filePath)) {
			return false;
		}

		// 3) Complete upload and post message
		return $this->completeUploadExternal($fileId, $filename, $message);
	}

	/**
	 * Call files.getUploadURLExternal to get upload URL and file id.
	 *
	 * @param string $filename
	 * @param int    $length
	 *
	 * @return array|null
	 */
	private function getUploadUrlExternal(string $filename, int $length): ?array
	{
		$url = 'https://slack.com/api/files.getUploadURLExternal';

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $this->botToken,
			],
			CURLOPT_POSTFIELDS     => [
				'filename' => $filename,
				'length'   => $length,
			],
		]);

		$raw = curl_exec($ch);
		if ($raw === false) {
			$this->lastError = 'cURL error (getUploadURLExternal): ' . curl_error($ch);
			curl_close($ch);
			return null;
		}

		curl_close($ch);

		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['ok'])) {
			$this->lastError = 'Slack files.getUploadURLExternal error: ' . ($data['error'] ?? 'unknown');
			return null;
		}

		if (empty($data['upload_url']) || empty($data['file_id'])) {
			$this->lastError = 'Slack files.getUploadURLExternal response is missing upload_url or file_id.';
			return null;
		}

		return [
			'upload_url' => $data['upload_url'],
			'file_id'    => $data['file_id'],
		];
	}

	/**
	 * Upload binary data to Slack-upload URL.
	 *
	 * @param string $uploadUrl
	 * @param string $filePath
	 *
	 * @return bool
	 */
	private function uploadBinary(string $uploadUrl, string $filePath): bool
	{
		$contents = file_get_contents($filePath);
		if ($contents === false) {
			$this->lastError = 'Unable to read file contents: ' . $filePath;
			return false;
		}

		$ch = curl_init($uploadUrl);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/octet-stream',
			],
			CURLOPT_POSTFIELDS     => $contents,
		]);

		$raw      = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($raw === false) {
			$this->lastError = 'cURL error (uploadBinary): ' . curl_error($ch);
			curl_close($ch);
			return false;
		}

		curl_close($ch);

		if ((int) $httpCode !== 200) {
			$this->lastError = 'Slack upload URL returned HTTP ' . $httpCode . ', body: ' . $raw;
			return false;
		}

		return true;
	}

	/**
	 * Call files.completeUploadExternal to attach file to channel with comment.
	 *
	 * @param string $fileId
	 * @param string $filename
	 * @param string $message
	 *
	 * @return bool
	 */
	private function completeUploadExternal(string $fileId, string $filename, string $message): bool
	{
		$url = 'https://slack.com/api/files.completeUploadExternal';

		$filesPayload = json_encode(
			[
				[
					'id'    => $fileId,
					'title' => $filename,
				],
			],
			JSON_UNESCAPED_UNICODE
		);

		if ($filesPayload === false) {
			$this->lastError = 'Failed to encode files payload for Slack.';
			return false;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $this->botToken,
			],
			CURLOPT_POSTFIELDS     => [
				'files'           => $filesPayload,
				'channel_id'      => $this->channelId,
				'initial_comment' => $message,
			],
		]);

		$raw = curl_exec($ch);
		if ($raw === false) {
			$this->lastError = 'cURL error (completeUploadExternal): ' . curl_error($ch);
			curl_close($ch);
			return false;
		}

		curl_close($ch);

		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['ok'])) {
			$this->lastError = 'Slack files.completeUploadExternal error: ' . ($data['error'] ?? 'unknown');
			return false;
		}

		return true;
	}

	/**
	 * Helper for JSON POST with Bearer token.
	 *
	 * @param string $url
	 * @param array  $data
	 *
	 * @return array|null
	 */
	private function curlJsonPost(string $url, array $data): ?array
	{
		$payload = json_encode($data, JSON_UNESCAPED_UNICODE);
		if ($payload === false) {
			$this->lastError = 'Failed to encode JSON payload.';
			return null;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $this->botToken,
				'Content-Type: application/json; charset=utf-8',
			],
			CURLOPT_POSTFIELDS     => $payload,
		]);

		$raw = curl_exec($ch);
		if ($raw === false) {
			$this->lastError = 'cURL error (curlJsonPost): ' . curl_error($ch);
			curl_close($ch);
			return null;
		}

		curl_close($ch);

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			$this->lastError = 'Unable to decode JSON response.';
			return null;
		}

		return $data;
	}
}
