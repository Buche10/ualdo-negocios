<?php

namespace App\Services\AdminTools;

use App\Models\Business;
use App\Models\User;
use App\Services\ApprovalGate;
use App\Services\AuditService;
use App\Services\BusinessContext;
use Closure;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

class AdminTool
{
    protected string $name;

    protected string $description;

    /** @var array<int, string> */
    protected array $allowedRoles = ['owner', 'receptionist', 'doctor'];

    protected bool $requiresApproval = false;

    protected ?Closure $handler = null;

    protected array $parameters = [];

    public function __construct(string $name, string $description)
    {
        $this->name = $name;
        $this->description = $description;
    }

    public static function make(string $name, string $description): self
    {
        return new self($name, $description);
    }

    /**
     * Set allowed roles for this tool.
     *
     * @param  array<int, string>  $roles
     */
    public function withRoles(array $roles): self
    {
        $this->allowedRoles = $roles;

        return $this;
    }

    /**
     * Mark this tool as requiring human approval via ApprovalGate.
     */
    public function requiresApproval(bool $requires = true): self
    {
        $this->requiresApproval = $requires;

        return $this;
    }

    public function withStringParameter(string $name, string $description, bool $required = true): self
    {
        $this->parameters[] = [
            'type' => 'string',
            'name' => $name,
            'description' => $description,
            'required' => $required,
        ];

        return $this;
    }

    public function withNumberParameter(string $name, string $description, bool $required = true): self
    {
        $this->parameters[] = [
            'type' => 'number',
            'name' => $name,
            'description' => $description,
            'required' => $required,
        ];

        return $this;
    }

    public function withArrayParameter(string $name, string $description, string $itemType = 'string', bool $required = true): self
    {
        $this->parameters[] = [
            'type' => 'array',
            'name' => $name,
            'description' => $description,
            'itemType' => $itemType,
            'required' => $required,
        ];

        return $this;
    }

    public function using(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Build the underlying Prism Tool with all security wrappers.
     */
    public function build(?User $user = null): Tool
    {
        $prismTool = (new Tool)
            ->as($this->name)
            ->for($this->description);

        foreach ($this->parameters as $param) {
            if ($param['type'] === 'string') {
                $prismTool->withStringParameter($param['name'], $param['description'], $param['required']);
            } elseif ($param['type'] === 'number') {
                $prismTool->withNumberParameter($param['name'], $param['description'], $param['required']);
            } elseif ($param['type'] === 'array') {
                $prismTool->withArrayParameter($param['name'], $param['description'], $param['itemType'], $param['required']);
            }
        }

        $prismTool->using(function (...$args) use ($user) {
            $currentUser = $user ?? auth()->user();
            /** @var Business|null $business */
            $business = BusinessContext::get() ?? $currentUser?->business;

            // (a) Enforce BusinessContext scope strictly
            $activeTenantId = BusinessContext::getTenantId();
            if (! $business || ! $activeTenantId || ($currentUser && $currentUser->business_id && $currentUser->business_id !== $activeTenantId)) {
                Log::warning("AdminTool [{$this->name}] executed with mismatching or inactive BusinessContext.");

                return json_encode([
                    'status' => 'error',
                    'message' => 'Error de seguridad: El contexto de negocio no es válido o no coincide.',
                ], JSON_UNESCAPED_UNICODE);
            }

            // (b) Enforce Role check
            if ($currentUser && ! empty($this->allowedRoles)) {
                $hasRole = false;
                foreach ($this->allowedRoles as $role) {
                    if ($currentUser->hasRole($role)) {
                        $hasRole = true;
                        break;
                    }
                }

                if (! $hasRole) {
                    Log::warning("User #{$currentUser->id} con rol '{$currentUser->role}' intentó ejecutar tool [{$this->name}] no autorizada.");

                    return json_encode([
                        'status' => 'error',
                        'message' => "Acceso denegado: El rol '{$currentUser->role}' no tiene permiso para ejecutar la acción '{$this->name}'.",
                    ], JSON_UNESCAPED_UNICODE);
                }
            }

            // (d) ApprovalGate Interception for irreversible/critical actions
            if ($this->requiresApproval && $currentUser) {
                $gate = app(ApprovalGate::class);
                $pending = $gate->createPendingAction(
                    user: $currentUser,
                    business: $business,
                    action: $this->name,
                    payload: $args
                );

                app(AuditService::class)->log(
                    action: "proposed:{$this->name}",
                    newValues: ['payload' => $args, 'token' => $pending->token],
                    user: $currentUser
                );

                return json_encode([
                    'status' => 'approval_required',
                    'action' => $this->name,
                    'token' => $pending->token,
                    'message' => "La acción '{$this->name}' requiere tu confirmación antes de aplicarse en la base de datos.",
                    'details' => $args,
                ], JSON_UNESCAPED_UNICODE);
            }

            // (c) Audit & Execute direct read/write tool
            try {
                $result = call_user_func_array($this->handler, $args);

                app(AuditService::class)->log(
                    action: "executed:{$this->name}",
                    newValues: ['args' => $args, 'result' => is_string($result) ? json_decode($result, true) ?? $result : $result],
                    user: $currentUser
                );

                return $result;
            } catch (\Throwable $e) {
                Log::error("Error ejecutando AdminTool [{$this->name}]: ".$e->getMessage());

                return json_encode([
                    'status' => 'error',
                    'message' => "Error al ejecutar '{$this->name}': ".$e->getMessage(),
                ], JSON_UNESCAPED_UNICODE);
            }
        });

        return $prismTool;
    }
}
