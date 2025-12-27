<?php

declare(strict_types=1);

require __DIR__ . "/autoloader.php";

use League\HTMLToMarkdown\HtmlConverter;

class DiscordExtension extends Minz_Extension
{
	#[\Override]
	public function init(): void
	{
		$this->registerTranslates();
		$this->registerHook("entry_before_add", [
			$this,
			"handleEntryBeforeAdd",
		]);
	}

	public function handleConfigureAction(): void
	{
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$now = new DateTime();
			$test = Minz_Request::hasParam("test");

			$config = [
				"url" => Minz_Request::paramString("url"),
				"username" => Minz_Request::paramString("username"),
				"avatar_url" => Minz_Request::paramString("avatar_url"),
				"ignore_autoread" => Minz_Request::paramBoolean(
					"ignore_autoread"
				),
				"embed_as_link_patterns" => Minz_Request::paramString(
					"embed_as_link_patterns"
				),
				"category_filter_patterns" => Minz_Request::paramString(
					"category_filter_patterns"
				),
			];

			$this->setSystemConfiguration($config);

			if ($test) {
				$this->sendMessage(
					$config["url"],
					$config["username"],
					$config["avatar_url"],
					[
						"content" =>
							"Test message from FreshRSS posted at " .
							$now->format("m/d/Y H:i:s"),
					]
				);
			}
		}
	}

	public function handleEntryBeforeAdd(FreshRSS_Entry $entry)
	{
		if (
			!$this->shouldSendBasedOnRead($entry) ||
			!$this->shouldSendBasedOnCategories($entry)
		) {
			return $entry; // block sending
		}

		$url = $entry->link();
		$embedAsLink = $this->shouldEmbedAsLink($entry);

		if ($embedAsLink) {
			$this->sendMessage(
				$this->getSystemConfigurationValue("url"),
				$this->getSystemConfigurationValue("username"),
				$this->getSystemConfigurationValue("avatar_url"),
				["content" => $url]
			);
		} else {
			$converter = new HtmlConverter(["strip_tags" => true]);

			$embed = [
				"url" => $url,
				"title" => $entry->title(),
				"color" => 2605643,
				"description" => $this->truncate(
					$converter->convert($entry->originalContent()),
					4000
				),
				"timestamp" => (new DateTime(
					"@" . $entry->date(true) / 1000
				))->format(DateTime::ATOM),
				"author" => [
					"name" => $entry->feed()->name(),
					"icon_url" => $this->favicon($entry->feed()->website()),
				],
				"footer" => [
					"text" => $this->getSystemConfigurationValue("username"),
					"icon_url" => $this->getSystemConfigurationValue(
						"avatar_url"
					),
				],
			];

			if ($thumb = $entry->thumbnail()) {
				$embed["thumbnail"] = array_filter([
					"url" => $thumb["url"],
					"width" => $thumb["width"] ?? null,
					"height" => $thumb["height"] ?? null,
				]);
			}

			$this->sendMessage(
				$this->getSystemConfigurationValue("url"),
				$this->getSystemConfigurationValue("username"),
				$this->getSystemConfigurationValue("avatar_url"),
				["embeds" => [$embed]]
			);
		}

		return $entry;
	}

	private function shouldSendBasedOnRead(FreshRSS_Entry $entry): bool
	{
		$config = $this->getSystemConfigurationValue("ignore_autoread", false);

		// If we ignore autoread AND the entry is read → do NOT send
		if ($config && $entry->isRead()) {
			return false;
		}

		return true;
	}

	private function shouldSendBasedOnCategories(FreshRSS_Entry $entry): bool
	{
		$feed = $entry->feed();
		$name = $feed->category()?->name() ?: 'Uncategorized'; // The default category is named "Uncategorized" if none is set
		$config = $this->getSystemConfigurationValue(
			"category_filter_patterns",
			""
		);
		$patterns = $this->patterns($config);

		foreach ($patterns as $pattern) {
			if (@preg_match($pattern, $name)) {
				return false; // at least one match → block
			}
		}

		return true;
	}

	private function shouldEmbedAsLink(FreshRSS_Entry $entry): bool
	{
		$url = $entry->link();
		$config = $this->getSystemConfigurationValue(
			"embed_as_link_patterns",
			""
		);
		$patterns = $this->patterns($config);

		foreach ($patterns as $pattern) {
			if (@preg_match($pattern, $url)) {
				return true; // at least one match → embed as link
			}
		}

		return false;
	}

	private function patterns(string $text): array
	{
		return array_filter(array_map("trim", explode("\n", $text)));
	}

	public function sendMessage($url, $username, $avatar_url, $body): void
	{
		try {
			$ch = curl_init($url);

			$data = [
				"username" => $username,
				"avatar_url" => $avatar_url,
			];

			if (isset($body["content"])) {
				$data["content"] = $body["content"];
			}

			if (isset($body["embeds"])) {
				$data["embeds"] = $body["embeds"];
			}

			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => json_encode($data),
				CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
			]);

			curl_exec($ch);
		} catch (Throwable $err) {
			Minz_Log::error("[Discord] ❌ " . $err);
		} finally {
			curl_close($ch);
		}
	}

	public function favicon(string $url): string
	{
		return "https://favicon.im/" . parse_url($url, PHP_URL_HOST);
	}

	public function truncate(string $text, int $length = 20): string
	{
		if (strlen($text) <= $length) {
			return $text;
		}

		$text = substr($text, 0, $length);
		$text = substr($text, 0, strrpos($text, " "));
		return $text . "...";
	}

	public function debug(mixed $any): void
	{
		$file = __DIR__ . "/debug.txt";

		file_put_contents($file, print_r($any, true), FILE_APPEND);
		file_put_contents($file, "\n----------------------\n\n", FILE_APPEND);
	}
}
