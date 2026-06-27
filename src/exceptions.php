<?php

/**
 * Typed exception hierarchy for the WPLM PHP SDK.
 */

declare(strict_types=1);

namespace WPLM\Client\Exceptions;

use Exception;
use Throwable;

/**
 * Base class for all errors raised by the SDK.
 */
class WplmException extends Exception
{
    public ?string $errorCode;
    public ?int $statusCode;

    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?int $statusCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
    }

    /**
     * Map a server error code to a concrete exception type.
     */
    public static function fromCode(string $code, string $message, ?int $status = null): WplmException
    {
        $map = [
            'license_not_found' => WplmNotFoundException::class,
            'wplm_not_found' => WplmNotFoundException::class,
            'expired' => WplmExpiredException::class,
            'license_expired' => WplmExpiredException::class,
            'license_suspended' => WplmSuspendedException::class,
            'license_revoked' => WplmRevokedException::class,
            'license_terminated' => WplmTerminatedException::class,
            'machine_limit_exceeded' => WplmLimitExceededException::class,
            'blacklisted' => WplmBlacklistedException::class,
            'license_pending' => WplmNotActiveException::class,
            'license_not_active' => WplmNotActiveException::class,
            'machine_not_found' => WplmMachineNotFoundException::class,
            'machine_inactive' => WplmMachineNotFoundException::class,
            'product_mismatch' => WplmProductMismatchException::class,
        ];
        $class = $map[$code] ?? WplmApiException::class;
        /** @var WplmException $instance */
        $instance = new $class($message, $code, $status);
        return $instance;
    }
}

/** The license/key was not found. */
class WplmNotFoundException extends WplmException
{
}

/** The license is past its expiry (plus any grace period). */
class WplmExpiredException extends WplmException
{
}

/** The license is temporarily suspended. */
class WplmSuspendedException extends WplmException
{
}

/** The license has been revoked. */
class WplmRevokedException extends WplmException
{
}

/** The license has been permanently terminated. */
class WplmTerminatedException extends WplmException
{
}

/** No activation seats are available under the overage strategy. */
class WplmLimitExceededException extends WplmException
{
}

/** The key, fingerprint, or IP is on a deny list. */
class WplmBlacklistedException extends WplmException
{
}

/** The license is not yet active (pending first payment/delivery). */
class WplmNotActiveException extends WplmException
{
}

/** The device/machine was not found or is inactive. */
class WplmMachineNotFoundException extends WplmException
{
}

/**
 * The license is bound to a different product than this client expects.
 *
 * Raised when the signed payload's `pid` does not match the configured product
 * id. Enforced online and offline from the cryptographically signed payload, so
 * a key issued for product A cannot run in product B's app.
 */
class WplmProductMismatchException extends WplmException
{
}

/** A generic API error that did not map to a more specific type. */
class WplmApiException extends WplmException
{
}

/** The network request failed (offline, timeout, DNS, TLS). */
class WplmNetworkException extends WplmException
{
}

/** A signed payload or CRL failed Ed25519 verification. */
class WplmSignatureInvalidException extends WplmException
{
}

/** The SDK was misconfigured (e.g. missing license key or base URL). */
class WplmConfigException extends WplmException
{
}
