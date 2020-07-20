<?php

namespace Sentry\Laravel\Tracing;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;

class Middleware
{
    /**
     * The current active transaction.
     *
     * @var \Sentry\Tracing\Transaction|null
     */
    protected $transaction;

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (app()->bound('sentry')) {
            $this->startTransaction($request, app('sentry'));
        }

        return $next($request);
    }

    /**
     * Handle the application termination.
     *
     * @param \Illuminate\Http\Request  $request
     * @param \Illuminate\Http\Response $response
     *
     * @return void
     */
    public function terminate($request, $response): void
    {
        if ($this->transaction !== null && app()->bound('sentry')) {
            $this->transaction->finish();
        }
    }

    private function startTransaction(Request $request, Hub $sentry): void
    {
        $path = '/' . ltrim($request->path(), '/');
        $fallbackTime = microtime(true);
        $sentryTraceHeader = $request->header('sentry-trace');

        $context = $sentryTraceHeader
            ? TransactionContext::fromTraceparent($sentryTraceHeader)
            : new TransactionContext;

        $context->op = 'http.server';
        $context->name = $path;
        $context->data = [
            'url' => $path,
            'method' => strtoupper($request->method()),
        ];
        $context->startTimestamp = $request->server('REQUEST_TIME_FLOAT', $fallbackTime);

        $this->transaction = $sentry->startTransaction($context);

        $sentry->configureScope(function (Scope $scope): void {
            $scope->setSpan($this->transaction);
        });

        if (!$this->addBootTimeSpans()) {
            // @TODO: We might want to move this together with the `RouteMatches` listener to some central place and or do this from the `EventHandler`
            app()->booted(function () use ($request, $fallbackTime): void {
                $spanContextStart = new SpanContext();
                $spanContextStart->op = 'app.bootstrap';
                $spanContextStart->startTimestamp = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', $fallbackTime);
                $spanContextStart->endTimestamp = microtime(true);
                $this->transaction->startChild($spanContextStart);
            });
        }
    }

    private function addBootTimeSpans(): bool
    {
        if (!defined('LARAVEL_START') || !LARAVEL_START) {
            return false;
        }

        if (!defined('SENTRY_AUTOLOAD') || !SENTRY_AUTOLOAD) {
            return false;
        }

        if (!defined('SENTRY_BOOTSTRAP') || !SENTRY_BOOTSTRAP) {
            return false;
        }

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'autoload';
        $spanContextStart->startTimestamp = LARAVEL_START;
        $spanContextStart->endTimestamp = SENTRY_AUTOLOAD;
        $this->transaction->startChild($spanContextStart);

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'bootstrap';
        $spanContextStart->startTimestamp = SENTRY_AUTOLOAD;
        $spanContextStart->endTimestamp = SENTRY_BOOTSTRAP;
        $this->transaction->startChild($spanContextStart);

        return true;
    }
}
