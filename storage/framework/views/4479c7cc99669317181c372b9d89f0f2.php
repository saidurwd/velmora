<?php $__env->startSection('body'); ?>
    <header class="border-b border-line">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="/" class="text-lg font-bold text-brand-700"><?php echo e(config('app.name')); ?></a>
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