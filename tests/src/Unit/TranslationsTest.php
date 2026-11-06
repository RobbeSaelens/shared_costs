<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Unit;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the Dutch translation matches the module's strings.
 */
#[Group('shared_costs')]
final class TranslationsTest extends UnitTestCase {

  /**
   * Tests that every string is translated, with its placeholders.
   */
  public function testDutch(): void {
    $root = dirname(__DIR__, 3);
    $translations = $this->poStrings("$root/translations/nl.po");
    $strings = $this->sourceStrings("$root/src");
    $this->assertNotEmpty($strings);

    $this->assertSame([], array_values(array_diff($strings, array_keys($translations))), 'Every string has a Dutch translation.');
    $this->assertSame([], array_values(array_diff(array_keys($translations), $strings)), 'Every translation is for a string that is still used.');
    foreach ($translations as $source => $translation) {
      $this->assertNotSame('', $translation, $source);
      $this->assertSame($this->placeholders($source), $this->placeholders($translation), "The placeholders of: $source");
    }
  }

  /**
   * Reads the translations of a .po file.
   *
   * @return array<string, string>
   *   The translations, keyed by their English source.
   */
  private function poStrings(string $path): array {
    $reader = new PoStreamReader();
    $reader->setURI($path);
    $reader->open();
    $strings = [];
    while ($item = $reader->readItem()) {
      $source = $item->getSource();
      $translation = $item->getTranslation();
      $this->assertIsString($source, 'No plurals: the module has none.');
      $this->assertIsString($translation);
      $strings[$source] = $translation;
    }
    return $strings;
  }

  /**
   * Finds the translatable strings in PHP code.
   *
   * These are the literal strings given to t(), TranslatableMarkup and
   * formatPlural(), and the defaults of constraint messages.
   *
   * @return list<string>
   *   The strings, without duplicates.
   */
  private function sourceStrings(string $directory): array {
    $strings = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $tokens = array_values(array_filter(
        \PhpToken::tokenize((string) file_get_contents($file->getPathname())),
        static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
      ));
      foreach ($tokens as $i => $token) {
        $call = $token->is(T_STRING) && in_array($token->text, ['t', 'TranslatableMarkup', 'formatPlural'], TRUE) && ($tokens[$i + 1] ?? NULL)?->text === '(';
        $message = $token->is(T_VARIABLE) && str_ends_with($token->text, 'Message') && ($tokens[$i + 1] ?? NULL)?->text === '=';
        $string = $tokens[$i + 2] ?? NULL;
        if (($call || $message) && $string?->is(T_CONSTANT_ENCAPSED_STRING)) {
          $strings[] = $this->unquote($string->text);
        }
      }
    }
    // Only placeholders ("@field_name: @label"): nothing to translate.
    $words = array_filter($strings, static fn (string $string): bool => (bool) preg_match('/\pL/u', (string) preg_replace('/[@%:][a-z_]+/', '', $string)));
    return array_values(array_unique($words));
  }

  /**
   * Returns the value of a PHP string literal.
   */
  private function unquote(string $literal): string {
    $inner = substr($literal, 1, -1);
    return $literal[0] === "'" ? strtr($inner, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($inner);
  }

  /**
   * Returns the placeholders in a string, sorted.
   *
   * @return list<string>
   *   The placeholders, such as @label.
   */
  private function placeholders(string $string): array {
    preg_match_all('/[@%:][a-z_]+/', $string, $matches);
    $placeholders = $matches[0];
    sort($placeholders);
    return $placeholders;
  }

}
