<?php

namespace Xstrm\Xstrm\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(array $event)
 * @method static void flush()
 * @method static void reset()
 * @method static string traceId()
 *
 * @see \Xstrm\Xstrm\Xstrm
 */
class Xstrm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Xstrm\Xstrm\Xstrm::class;
    }
}
