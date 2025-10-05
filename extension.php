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
    $this->registerHook("entry_before_add", [$this, "handleEntryBeforeAdd"]);
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
        "ignore_autoread" => Minz_Request::paramBoolean("ignore_autoread"),
        "embed_as_link_patterns" => Minz_Request::paramString("embed_as_link_patterns"),
      ];

      $this->setSystemConfiguration($config);

      if ($test) {
        $this->sendMessage($config["url"], $config["username"], $config["avatar_url"], [
          "content" => "Test message from FreshRSS posted at " . $now->format("m/d/Y H:i:s"),
        ]);
      }
    }
  }

  public function handleEntryBeforeAdd($entry)
  {
    $shouldIgnoreAutoread = $this->getSystemConfigurationValue("ignore_autoread", false);

    if ($shouldIgnoreAutoread && $entry->isRead()) {
      return $entry;
    }

    $url = $entry->link();

    $embedAsLinkPatterns = $this->getSystemConfigurationValue("embed_as_link_patterns", "");
    $embedAsLinkPatterns = array_filter(array_map('trim', explode("\n", $embedAsLinkPatterns)));
    $embedAsLink = false;

    foreach ($embedAsLinkPatterns as $pattern) {
      if (!empty($pattern) && @preg_match($pattern, $url)) {
        $embedAsLink = true;
        break;
      }
    }

    if ($embedAsLink) {
      $this->sendMessage(
        $this->getSystemConfigurationValue("url"),
        $this->getSystemConfigurationValue("username"),
        $this->getSystemConfigurationValue("avatar_url"),
        ["content" => $url]
      );
    } else {
      $converter = new HtmlConverter(["strip_tags" => true]);
      $thumb = $entry->thumbnail();
      $descr = $entry->originalContent();
      $embed = [
        "url" => $url,
        "title" => $entry->title(),
        "color" => 2605643,
        "description" => $this->truncate($converter->convert($descr), 4000),
        "timestamp" => (new DateTime("@" . $entry->date(true) / 1000))->format(DateTime::ATOM),
        "author" => [
          "name" => $entry->feed()->name(),
          "icon_url" => $this->favicon($entry->feed()->website()),
        ],
        "footer" => [
          "text" => $this->getSystemConfigurationValue("username"),
          "icon_url" => $this->getSystemConfigurationValue("avatar_url"),
        ],
      ];

      if ($thumb !== null) {
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

  public function sendMessage($url, $username, $avatar_url, $body)
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

  public function debug(mixed $any): void
  {
    $file = __DIR__ . "/debug.txt";

    file_put_contents($file, print_r($any, true), FILE_APPEND);
    file_put_contents($file, "\n----------------------\n\n", FILE_APPEND);
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
    $text .= "...";

    return $text;
  }
}
