<?php

namespace App\Services;

/**
 * Translates the Arabic text the API sends into English, for a login that chose English.
 *
 * The server keeps building every message and label in Arabic — the payroll engine freezes its lines
 * into an approved month's snapshot, the golden file holds them, queued jobs have no request — so the
 * text is translated on the way out instead, and only in a response to an English request. Nothing
 * stored ever changes language.
 *
 * Only text fields are touched (see TEXT_KEYS): a name, a note or any other value a user typed is
 * never translated, even when it happens to match a dictionary entry, so it can't come back from an
 * edit form in another language.
 *
 * The dictionary (resources/lang-ui/en.json) is keyed by the Arabic text exactly as the code builds
 * it. A built string with values inside it is a template: {1}, {2}… (or %s / %d / :attribute, as in the
 * code) match any value, and the English puts them back in order. A matched value is translated too,
 * when it is itself an entry — «مخالفة مرورية{1}» carries a label inside it.
 */
class UiTranslator
{
    /** Response keys whose value is text the system wrote, never data. */
    private const TEXT_KEYS = ['message', 'error', 'label', 'formula', 'text', 'basis', 'title', 'hint', 'note_label', 'warning'];

    /** Keys whose every string, at any depth, is system text (validation errors, lists of warnings). */
    private const TEXT_TREES = ['errors', 'warnings', 'issues', 'alerts'];

    /** @var array<string, string>|null */
    private ?array $exact = null;

    /** @var array<int, array{0: string, 1: string}>|null regex => English template */
    private ?array $patterns = null;

    /**
     * True inside a request from a login that chose English. A job, a command or a test with no
     * request reads Arabic, as everything always did.
     */
    public static function wantsEnglish(): bool
    {
        return app()->bound('request') && str_starts_with(strtolower((string) request()->header('X-Ui-Language')), 'en');
    }

    /**
     * Walks a decoded JSON payload and translates its text fields.
     */
    public function translatePayload(mixed $data, ?string $key = null, bool $inTextTree = false): mixed
    {
        if (is_array($data)) {
            foreach ($data as $k => $value) {
                $isTree = $inTextTree || (is_string($k) && in_array($k, self::TEXT_TREES, true));
                $data[$k] = $this->translatePayload($value, is_string($k) ? $k : $key, $isTree);
            }

            return $data;
        }

        if (is_string($data) && $data !== '' && ($inTextTree || $this->isTextKey($key)) && preg_match('/\p{Arabic}/u', $data)) {
            return $this->translate($data);
        }

        return $data;
    }

    /**
     * One string: the exact entry, else the first template that matches it, else the string unchanged.
     */
    public function translate(string $text, int $depth = 0): string
    {
        $this->load();

        if (isset($this->exact[$text])) {
            return $this->exact[$text];
        }

        foreach ($this->patterns as [$regex, $english]) {
            if (preg_match($regex, $text, $m)) {
                return preg_replace_callback('/\{(\d+)\}/', function ($p) use ($m, $depth) {
                    $value = $m[(int) $p[1]] ?? '';
                    if ($depth >= 3 || ! preg_match('/\p{Arabic}/u', $value)) {
                        return $value;
                    }
                    // A value keeps its own spacing; only what is inside it is looked up.
                    preg_match('/^(\s*)(.*?)(\s*)$/us', $value, $s);

                    return $s[1].$this->translate($s[2], $depth + 1).$s[3];
                }, $english);
            }
        }

        return $text;
    }

    private function isTextKey(?string $key): bool
    {
        return $key !== null && (in_array($key, self::TEXT_KEYS, true) || str_ends_with($key, '_label') || str_ends_with($key, '_basis'));
    }

    private function load(): void
    {
        if ($this->exact !== null) {
            return;
        }

        $entries = json_decode((string) file_get_contents(resource_path('lang-ui/en.json')), true) ?: [];
        unset($entries['_comment']);

        $this->exact = [];
        $this->patterns = [];
        $placeholder = '/\{\d+\}|%(?:\.\d+)?0?\d*[sdf]|:[a-z_]+/';

        foreach ($entries as $arabic => $english) {
            if (! preg_match($placeholder, $arabic)) {
                $this->exact[$arabic] = $english;

                continue;
            }
            $parts = preg_split($placeholder, str_replace('%%', '%', $arabic));
            // (.*?): a value may be empty — «{1} يوم مدفوع{2}» when there is no paid leave to add.
            $regex = '/^'.implode('(.*?)', array_map(fn ($p) => preg_quote($p, '/'), $parts)).'$/us';
            $this->patterns[] = [$regex, $english, mb_strlen(implode('', $parts))];
        }

        // The most specific template first: the one with the most fixed text.
        usort($this->patterns, fn ($a, $b) => $b[2] <=> $a[2]);
        $this->patterns = array_map(fn ($p) => [$p[0], $p[1]], $this->patterns);
    }
}
