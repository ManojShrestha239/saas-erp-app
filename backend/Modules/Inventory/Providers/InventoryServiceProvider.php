<?php

declare(strict_types=1);

namespace Modules\Inventory\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\UnitOfMeasure;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Policies\CategoryPolicy;
use Modules\Inventory\Policies\ProductPolicy;
use Modules\Inventory\Policies\StockAdjustmentPolicy;
use Modules\Inventory\Policies\StockPolicy;
use Modules\Inventory\Policies\UnitOfMeasurePolicy;
use Modules\Inventory\Policies\WarehousePolicy;
use Shared\Providers\ModuleServiceProvider;

final class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Inventory';
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(UnitOfMeasure::class, UnitOfMeasurePolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(StockLevel::class, StockPolicy::class);
        Gate::policy(StockMovement::class, StockPolicy::class);
        Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class);
    }
}
