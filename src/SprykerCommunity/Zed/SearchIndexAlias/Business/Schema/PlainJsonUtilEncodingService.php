<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Schema;

use JsonException;
use Spryker\Shared\SearchElasticsearch\Dependency\Service\SearchElasticsearchToUtilEncodingServiceInterface;

/**
 * A minimal, dependency-free implementation of core's own `SearchElasticsearchToUtilEncodingServiceInterface`
 * -- needed only to satisfy `Spryker\Zed\SearchElasticsearch\Business\Definition\Reader\IndexDefinitionReader`'s
 * constructor when this package reconstructs core's own schema-JSON loading pipeline standalone (see
 * `SchemaIndexDefinitionResolver`'s own doc block for why). Deliberately plain `json_encode`/`json_decode`
 * rather than depending on `spryker/util-encoding-service` -- that package isn't otherwise a dependency
 * here, and this interface only needs two trivial methods.
 */
class PlainJsonUtilEncodingService implements SearchElasticsearchToUtilEncodingServiceInterface
{
    // Deliberately no native type hints on either method below -- `SearchElasticsearchToUtilEncodingServiceInterface`
    // declares none either (implicit `mixed`), and PHP's contravariance rules forbid an implementing
    // class from narrowing an inherited untyped (`mixed`) parameter, so adding `array $value` etc. here
    // would be a fatal "declaration must be compatible" error, not just a style choice.
    // phpcs:disable SprykerStrict.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    // phpcs:disable SprykerStrict.TypeHints.ReturnTypeHint.MissingNativeTypeHint

    /**
     * @param array<mixed> $value
     * @param int|null $options
     * @param int|null $depth
     *
     * @return string|null
     */
    public function encodeJson($value, $options = null, $depth = null)
    {
        try {
            return json_encode($value, ($options ?? 0) | JSON_THROW_ON_ERROR, max(1, $depth ?? 512));
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter $assoc is mandated by the
     *  interface -- core's own IndexDefinitionReader always calls this with `true`, and this class only
     *  ever needs to return an array (never a stdClass), so `false` isn't a case worth honoring here.
     *
     * @param string $jsonValue
     * @param bool $assoc
     * @param int|null $depth
     * @param int|null $options
     *
     * @return array<mixed>|null
     */
    public function decodeJson($jsonValue, $assoc = false, $depth = null, $options = null)
    {
        try {
            /** @var array<mixed> $decoded */
            $decoded = json_decode($jsonValue, true, max(1, $depth ?? 512), ($options ?? 0) | JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException) {
            return null;
        }
    }

    // phpcs:enable SprykerStrict.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    // phpcs:enable SprykerStrict.TypeHints.ReturnTypeHint.MissingNativeTypeHint
}
