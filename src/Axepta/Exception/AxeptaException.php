<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception;

/**
 * Marker shared by every exception of the protocol layer.
 *
 * Lets an integrator catch "anything coming from Axepta" without enumerating classes, and lets the
 * plugin add new ones without breaking compatibility.
 */
interface AxeptaException extends \Throwable
{
}
