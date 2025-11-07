<?php

const OPENAI_API_COMPLETIONS_URL = '/v1/chat/completions';
function endsWithPunctuation($str)
{
	$pattern = '/\p{P}$/u'; // regex pattern for ending with punctuation marks
	return preg_match($pattern, $str);
}

function encodeURIComponent($str) {
    $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
    return strtr(rawurlencode($str), $revert);
}

function _dealResponse($openai_response)
{
	return $openai_response->choices[0]->delta->content ?? '';
}

function _errorHtmlSuffix($error_msg)
{
	return 'Ooooops!!!!<br><br>' . $error_msg;
}

function streamOpenAiApi(object $config, string $prompt, string $content, callable $task_callback, callable $finish_callback)
{
	$post_fields = json_encode(array(
		"model" => $config->model,
		"messages" => array(
			array(
				"role" => "system",
				"content" => $prompt,
			),
			array(
				"role" => "user",
				"content" => $content,
			),
		),
		"max_tokens" => $config->max_tokens,
		"temperature" => $config->temperature,
		"stream" => true,
	));

	Minz_Log::debug('Openai base url:' . $config->openai_base_url);

	$curl_info = [
		CURLOPT_URL            => $config->openai_base_url . OPENAI_API_COMPLETIONS_URL,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING       => 'utf-8',
		CURLOPT_MAXREDIRS      => 10,
		CURLOPT_TIMEOUT        => $config->api_timeout,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST  => 'POST',
		CURLOPT_POSTFIELDS     => $post_fields,
		CURLOPT_HTTPHEADER     => [
			"Content-Type: application/json",
			"Authorization: Bearer $config->openai_api_key",
			"x-portkey-provider: $config->provider",
		],
	];

	$stream_buffer = '';
	$error_buffer = '';
	$http_error_handled = false;

	$curl_info[CURLOPT_WRITEFUNCTION] = function ($curl_handle, $data) use (&$stream_buffer, &$error_buffer, &$http_error_handled, $task_callback, $finish_callback) {
		Minz_Log::debug('Receive msg:' . $data);

		$status = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);

		// Handle non-200 HTTP responses by buffering until we can decode the error payload.
		if ($status && $status != 200) {
			if (!$http_error_handled) {
				$error_buffer .= $data;
				$decoded_error = json_decode($error_buffer);
				if ($decoded_error !== null) {
					$message = isset($decoded_error->error->message) ? $decoded_error->error->message : _t('gen.error.unknown');
					$task_callback(_errorHtmlSuffix($message));
					$finish_callback();
					$http_error_handled = true;
				}
			}

			return strlen($data);
		}

		$stream_buffer .= $data;
		$stream_buffer = str_replace("\r\n", "\n", $stream_buffer);

		while (($event_separator_pos = strpos($stream_buffer, "\n\n")) !== false) {
			$raw_event = substr($stream_buffer, 0, $event_separator_pos);
			$stream_buffer = substr($stream_buffer, $event_separator_pos + 2);

			$lines = preg_split('/\r?\n/', $raw_event);
			$event_name = '';
			$data_lines = [];

			foreach ($lines as $line) {
				$line = trim($line);
				if ($line === '') {
					continue;
				}

				if (stripos($line, 'event:') === 0) {
					$event_name = trim(substr($line, strlen('event:')));
					continue;
				}

				if (stripos($line, 'data:') === 0) {
					$data_lines[] = ltrim(substr($line, strlen('data:')));
				}
			}

			$event_data = implode("\n", $data_lines);

			if ($event_name === 'done' || $event_data === '[DONE]') {
				$finish_callback();
				continue;
			}

			if ($event_data === '') {
				continue;
			}

			$decoded_payload = json_decode($event_data);
			if ($decoded_payload === null) {
				// Wait for more data if JSON is incomplete.
				$stream_buffer = $raw_event . "\n\n" . $stream_buffer;
				break;
			}

			$delta = _dealResponse($decoded_payload);
			if ($delta !== '') {
				$task_callback($delta);
			}
		}

		return strlen($data);
	};

	$curl = curl_init();

	curl_setopt_array($curl, $curl_info);
	$response = curl_exec($curl);

	// handle the error request of curl
	if (curl_errno($curl)) {
		$task_callback(_errorHtmlSuffix(curl_error($curl)));
		$finish_callback();
	}

	curl_close($curl);
	return $response;
}
