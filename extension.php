<?php

declare(strict_types=1);

class DiscordExtension extends Minz_Extension {

		#[\Override]
		public function init(): void {
			$this->registerTranslates();
			$this->registerHook("entry_before_add", [$this, "handleEntryBeforeAdd"]);
		}

		public function handleConfigureAction(): void {
			$this->registerTranslates();

			if (Minz_Request::isPost()) {
				$now = new DateTime();
				$test = Minz_Request::hasParam("test");
				$config = [
					"url" => Minz_Request::paramString("url"),
					"username" => Minz_Request::paramString("username"),
					"avatar_url" => Minz_Request::paramString("avatar_url"),
					"ignore_autoread" => Minz_Request::paramBoolean("ignore_autoread")
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

	public function handleEntryBeforeAdd($entry) {
		$ignoreAutoread = $this->getSystemConfigurationValue("ignore_autoread", false);

		// If ignore_autoread is enabled, skip entries that are automatically marked as read
		// when they appear, based on the feeds filters actions
		// https://freshrss.github.io/FreshRSS/en/users/10_filter.html
		if ($ignoreAutoread && $entry->isRead()) {
				return $entry;
		}

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
		$eol = "\n";
		$nl = "  " . $eol;
		$break = $nl . $nl;

		// Remove white space between tags
		$text = preg_replace("/>\s+</i", "><", $text);

		// Remove white space after tag start
		$text = preg_replace("/<\s+/", '<', $text);

		// Remove white space before tag close
		$text = preg_replace("/\s+>/", '>', $text);

		// Remove excessive white space
		$text = preg_replace("/\s+/", ' ', $text);

		// remove comments
		$text = preg_replace('/<!--([^-](?!(->)))*-->/', '', $text);

		// Strip tags unless the have an equivalent markdown syntax
		$text = strip_tags($text, '<br><h1><h2><h3><p><pre><tr><ul><li><blockquote><em><del><code><strong><a>');

		// Transform <br>
		$text = preg_replace("/<br\s*\/?>/i", $nl, $text);

		// Transform <h1>
		$text = preg_replace("/<h1[^>]*>(.*?)<\/h1>/i", "{$break}# $1{$break}", $text);

		// Transform <h2>
		$text = preg_replace("/<h2[^>]*>(.*?)<\/h2>/i", "{$break}## $1{$break}", $text);

		// Transform <h3>
		$text = preg_replace("/<h3[^>]*>(.*?)<\/h3>/i", "{$break}## $1{$break}", $text);

		// Transform <p>
		$text = preg_replace("/<p[^>]*>(.*?)<\/p>/i", "{$break}$1{$break}", $text);

		// Transform <pre>
		$text = preg_replace("/<pre[^>]*>(.*?)<\/pre>/i", "{$break}```{$eol}$1{$eol}```{$break}", $text);

		// Transform <tr>
		$text = preg_replace("/<tr[^>]*>(.*?)<\/tr>/i", "{$break}$1{$break}", $text);

		// Transform <ul>
		$text = preg_replace("/<ul[^>]*>(.*?)<\/ul>/i", "{$break}$1{$break}", $text);

		// Transform <li>
		$text = preg_replace("/<li[^>]*>(.*?)<\/li>/i", "- $1{$eol}", $text);

		// Transform <blockquote>
		$text = preg_replace("/<blockquote[^>]*>(.*?)<\/blockquote>/is", "{$break}> $1{$break}", $text);

		// Transform <em>
		$text = preg_replace("/<em[^>]*>(.*?)<\/em>/i", "*$1*", $text);

		// Transform <del>
		$text = preg_replace("/<del[^>]*>(.*?)<\/del>/i", "~~$1~~", $text);

		// Transform <code>
		$text = preg_replace("/<code[^>]*>(.*?)<\/code>/is", "`$1`", $text);

		// Transform <strong>
		$text = preg_replace("/<strong[^>]*>(.*?)<\/strong>/i", "**$1**", $text);

		// Transform <a>
		$text = preg_replace_callback(
			"/<a\s+href=['\"](.*?)['\"][^>]*>(.*?)<\/a>/i",
			fn($matches) => "[{$matches[2]}]({$matches[1]})",
			$text
		);

		// Convert Unicode characters represented as \uXXXX to UTF-8
		$text = preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			fn($matches) => mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UTF-16BE'),
			$text
		);

		// Trim end
		$text = preg_replace("/\s+$/i", '', $text);

		// Trim start
		$text = preg_replace("/^\s+/i", '', $text);

		// Trim start of lines
		$text = preg_replace("/^ +/im", '', $text);

		// Trim excessive line breaks
		$text = preg_replace("/(\s*\n\s*\n)+/i", $break, $text);

		return $text;
	}
}
