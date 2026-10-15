<?php

namespace giantbits\crelish\components;

/**
 * For queue jobs: carries the Sentry trace of the request that queued the job, so the job
 * continues that trace (see SentryTracing). Filled in when the job is pushed.
 */
trait CarriesSentryTrace
{
  public ?string $sentryTrace = null;
  public ?string $sentryBaggage = null;
}
