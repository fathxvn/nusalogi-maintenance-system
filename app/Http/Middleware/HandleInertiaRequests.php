<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn (): ?array => $request->user()?->only(['id', 'name', 'role']),
            ],
            'navigation' => fn (): array => $this->navigation($request),
        ];
    }

    /**
     * @return list<array{id: string, label: string, href: ?string, active: bool, inertia: bool}>
     */
    private function navigation(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        $items = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard'],
            ['id' => 'work-orders', 'label' => 'Work Order', 'route' => null],
            ['id' => 'customers', 'label' => 'Customer', 'route' => null],
            ['id' => 'assets', 'label' => 'Asset', 'route' => null],
            ['id' => 'invoices', 'label' => 'Invoice', 'route' => null],
        ];

        if ($user->hasRole('admin')) {
            $items[] = ['id' => 'users', 'label' => 'User Management', 'route' => 'admin.users.index'];
        }

        $items[] = ['id' => 'preview', 'label' => 'Pratinjau Layout', 'route' => 'migration.test'];

        return array_map(function (array $item) use ($request): array {
            $routeName = $item['route'];
            $hasRoute = $routeName !== null && Route::has($routeName);
            $routePattern = $item['id'] === 'users' ? 'admin.users.*' : $routeName;

            return [
                'id' => $item['id'],
                'label' => $item['label'],
                'href' => $hasRoute ? route($routeName) : null,
                'active' => $hasRoute && $request->routeIs($routePattern),
                'inertia' => $routeName === 'migration.test',
            ];
        }, $items);
    }
}
