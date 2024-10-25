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
		$thumbnail = $entry->thumbnail();

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
						"description" => strip_tags($entry->originalContent()),
						"timestamp" => (new DateTime('@'. $entry->date(true)/1000))->format(DateTime::ATOM),
						"author" => [
							"name" => $entry->feed()->name(),
							"icon_url" => $this->getSystemConfigurationValue("avatar_url") // Would love this to be feed icon
						],
						"thumbnail" => isset($thumbnail) ? [
							"url" => $thumbnail["url"],
							"width" => $thumbnail["width"] ?? null,
							"height" => $thumbnail["height"] ?? null,
						] : null
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
}
