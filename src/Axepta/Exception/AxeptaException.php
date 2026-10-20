<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception;

/**
 * Marker shared by every exception the protocol layer throws itself.
 *
 * Errors raised by PHP - a failing entropy source, a type error, a failed assertion - are not
 * covered.
 *
 * Lets an integrator catch "anything coming from Axepta" without enumerating classes, and lets the
 * plugin add new ones without breaking compatibility. Each also extends the SPL exception it
 * replaces, so a `catch (\InvalidArgumentException)` written against an earlier version still
 * holds.
 *
 * A substituted implementation of a protocol interface - `BlowfishEcbInterface`, say - throws what
 * it chooses: the marker only covers the plugin's own classes.
 */
interface AxeptaException extends \Throwable
{
}
