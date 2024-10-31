<?php

declare(strict_types=1);

class DiscordExtension extends Minz_Extension {

		#[\Override]
		public function init(): void {
			$this->registerTranslates();
			$this->registerHook("entry_before_insert", [$this, "handleEntryBeforeInsert"]);
		}

		public function handleConfigureAction(): void {
			$this->registerTranslates();

			if (Minz_Request::isPost()) {
				$now = new DateTime();
				$test = Minz_Request::hasParam("test");
				$config = [
					"url" => Minz_Request::paramString("url"),
					"username" => Minz_Request::paramString("username"),
					"avatar_url" => Minz_Request::paramString("avatar_url")
				];

				$this->setSystemConfiguration($config);

				if ($test) {
					$this->sendMessage(
						$config["url"],
						$config["username"],
						$config["avatar_url"],
						[
							"content" => "Test message from FreshRSS posted at " . $now->format('m/d/Y H:i:s')
						]
					);
				}
			}
	}

	public function handleEntryBeforeInsert($entry) {
		$this->sendMessage(
			$this->getSystemConfigurationValue("url"),
			$this->getSystemConfigurationValue("username"),
			$this->getSystemConfigurationValue("avatar_url"),
			[
				"embeds" => [
					[
						"title" => $entry->title(),
						"url" => $entry->link(),
						"color" => 2605643,
						"description" => $this->truncate($this->markdownify($entry->originalContent()), 2000),
						"timestamp" => (new DateTime('@'. $entry->date(true)/1000))->format(DateTime::ATOM),
						"author" => [
							"name" => $entry->feed()->name(),
							"icon_url" => $this->favicon($entry->feed()->website())
						],
						"footer" => [
							"text" =>  $this->getSystemConfigurationValue("username"),
							"icon_url" => $this->getSystemConfigurationValue("avatar_url")
						]
					]
				]
			]
		);

		return $entry;
	}

	public function sendMessage($url, $username, $avatar_url, $body) {
		try {
			$ch = curl_init($url);
			$data = [
				"username" => $username,
				"avatar_url" => $avatar_url,
			];

			if(isset($body["content"])) {
				$data["content"] = $body["content"];
			}

			if(isset($body["embeds"])) {
				$data["embeds"] = $body["embeds"];
			}

			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
			curl_exec($ch);
		} catch (Throwable $err) {
			Minz_Log::error("[Discord] ❌ " . $err);
		} finally {
			curl_close($ch);
		}
	}

	public function favicon(string $url): string {
		return "https://favicon.im/" . parse_url($url, PHP_URL_HOST);
	}

	public function truncate(string $text, int $length = 20): string {
		if (strlen($text) <= $length) {
			return $text;
		}

		$text = substr($text, 0, $length);
		$text = substr($text, 0, strrpos($text, " "));
		$text .= "...";

		return $text;
	}

	public function markdownify(string $text): string {
		// Remove spaces between tags
		$text = preg_replace("/(>)\s*(<)/i", "$1$2", $text);

		// Allow <br> and <tr> tags, strip all others
		$text = strip_tags($text, '<br><tr><ul><li><em><strong>');

		// Replace <br> with "  \n"
		$text = preg_replace("/<br\s*\/?>/i", "  \n", $text);

		// Replace <tr> and </tr> with "  \n"
		$text = preg_replace("/<tr[^>]*>/i", "  \n", $text);
		$text = preg_replace("/<\/tr[^>]*>/i", "  \n", $text);

		// Replace <ul> and </ul> with "  \n"
		$text = preg_replace("/<\/?ul>/i", "  \n", $text);

		// Replace <li> with "- " and </li> with "\n"
		$text = preg_replace("/<li>/i", "- ", $text);
		$text = preg_replace("/<\/li>/i", "\n", $text);

		// Replace <em> and </em> with "*"
		$text = preg_replace("/<\/?em>/i", "*", $text);

		// Replace <strong> and </strong> with "**"
		$text = preg_replace("/<\/?strong>/i", "**", $text);

		// Prevent excessive line breaks by replacing three or more with just two
		$text = preg_replace("/(  \n){3,}/i", "  \n  \n", $text);

		// Convert Unicode characters represented as \uXXXX to UTF-8
		$text = preg_replace_callback(
				'/\\\\u([0-9a-fA-F]{4})/',
				fn($matches) => mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UTF-16BE'),
				$text
		);

		return $text;
	}
}
