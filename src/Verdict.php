<?php

declare(strict_types=1);

namespace AIAgentAllowlist;

/**
 * One API response with convenience accessors.
 *
 * Every raw field stays available through get(), toArray() or array access
 * ($verdict['page_types']), so fields added to the API later need no client update.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class Verdict implements \ArrayAccess, \JsonSerializable
{
    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data decoded JSON body of /api/check
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /** "allow", "deny", "flag", or null when no verdict was returned. */
    public function verdict(): ?string
    {
        $v = $this->get('verdict');
        return is_string($v) ? $v : null;
    }

    /** True only when the verdict is "allow". "flag" and "deny" both return false. */
    public function allowed(): bool
    {
        return $this->verdict() === 'allow';
    }

    public function denied(): bool
    {
        return $this->verdict() === 'deny';
    }

    public function flagged(): bool
    {
        return $this->verdict() === 'flag';
    }

    /** True when the domain is in the page-type database. */
    public function found(): bool
    {
        return (bool) $this->get('found', false);
    }

    /** "url" or "domain_root". */
    public function verdictScope(): ?string
    {
        $v = $this->get('verdict_scope');
        return is_string($v) ? $v : null;
    }

    /**
     * @return array<string, mixed> {layer, id, note}
     */
    public function matched(): array
    {
        $m = $this->get('matched');
        return is_array($m) ? $m : [];
    }

    /** high_value_hosts, page_type_db, rules or default. */
    public function matchedLayer(): ?string
    {
        $m = $this->matched();
        return isset($m['layer']) ? (string) $m['layer'] : null;
    }

    /** The page type or rule id that produced the verdict, for example "login". */
    public function matchedId(): ?string
    {
        $m = $this->matched();
        return isset($m['id']) ? (string) $m['id'] : null;
    }

    public function matchedNote(): ?string
    {
        $m = $this->matched();
        return isset($m['note']) ? (string) $m['note'] : null;
    }

    /**
     * Verified page-type map, for example ["login" => "https://...", "pricing" => "https://..."].
     *
     * @return array<string, string>
     */
    public function pageTypes(): array
    {
        $p = $this->get('page_types');
        return is_array($p) ? $p : [];
    }

    public function language(): ?string
    {
        $v = $this->get('language');
        return is_string($v) ? $v : null;
    }

    /**
     * @return mixed string or array, as returned by the API
     */
    public function iabCategory()
    {
        return $this->get('iab_category');
    }

    /**
     * @return array<int, mixed>
     */
    public function filteringCategories(): array
    {
        $v = $this->get('filtering_categories');
        return is_array($v) ? $v : [];
    }

    /**
     * @return mixed
     */
    public function openPageRank()
    {
        return $this->get('open_page_rank');
    }

    /**
     * @return mixed
     */
    public function globalRank()
    {
        return $this->get('global_rank');
    }

    public function remainingLookups(): ?int
    {
        $v = $this->get('remaining_lookups');
        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return array_key_exists((string) $offset, $this->data);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get((string) $offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('Verdict is read-only');
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        throw new \LogicException('Verdict is read-only');
    }

    /**
     * @return array<string, mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->data;
    }
}
