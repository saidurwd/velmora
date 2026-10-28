<?php $__env->startSection('body'); ?>
    <header class="border-b border-line">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="<?php echo e(url('/'.app()->getLocale())); ?>" class="text-lg font-bold text-brand-700"><?php echo e(config('app.name')); ?></a>
            <?php if (isset($component)) { $__componentOriginal39cc230a9e1f8a283e6a74b331d667dc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal39cc230a9e1f8a283e6a74b331d667dc = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'platform::components.language-switcher','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('platform::language-switcher'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal39cc230a9e1f8a283e6a74b331d667dc)): ?>
<?php $attributes = $__attributesOriginal39cc230a9e1f8a283e6a74b331d667dc; ?>
<?php unset($__attributesOriginal39cc230a9e1f8a283e6a74b331d667dc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal39cc230a9e1f8a283e6a74b331d667dc)): ?>
<?php $component = $__componentOriginal39cc230a9e1f8a283e6a74b331d667dc; ?>
<?php unset($__componentOriginal39cc230a9e1f8a283e6a74b331d667dc); ?>
<?php endif; ?>
        </div>
    </header>

    <main id="main" class="mx-auto max-w-6xl px-4 py-8" tabindex="-1">
        <?php echo $__env->yieldContent('content'); ?>
    </main>

    <footer class="border-t border-line bg-surface-alt">
        <div class="mx-auto max-w-6xl px-4 py-6 text-sm text-muted">
            &copy; <?php echo e(now()->year); ?> <?php echo e(config('app.name')); ?>

        </div>
    </footer>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.base', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/saidur.rahman/Code/velmora/resources/views/layouts/storefront.blade.php ENDPATH**/ ?>