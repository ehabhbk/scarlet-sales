<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\AuditLog;
use Symfony\Component\HttpFoundation\Response;

class AuditLogMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (auth()->check() && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            try {
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'action' => $this->getAction($request->method()),
                    'subject_type' => null,
                    'subject_id' => null,
                    'old_values' => null,
                    'new_values' => $request->except(['password', 'password_confirmation', '_token']),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Exception $e) {
                // Silently fail audit logging to not break the request
            }
        }

        return $response;
    }

    private function getAction(string $method): string
    {
        return match ($method) {
            'POST' => 'created',
            'PUT', 'PATCH' => 'updated',
            'DELETE' => 'deleted',
            default => 'accessed',
        };
    }
}
