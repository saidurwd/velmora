<?php $__env->startSection('title', __('errors.429.title')); ?>

<?php $__env->startSection('content'); ?>
    <section class="mx-auto max-w-xl py-16 text-center">
        <p class="text-sm font-semibold text-muted"><?php echo e(__('errors.error_code', ['code' => 429])); ?></p>
        <h1 class="mt-2 text-3xl font-bold"><?php echo e(__('errors.429.title')); ?></h1>
        <p class="mt-4 text-muted"><?php echo e(__('errors.429.message')); ?></p>
        <a href="/" class="mt-8 inline-block rounded-md bg-brand-600 px-5 py-3 font-medium text-white hover:bg-brand-700"><?php echo e(__('errors.back_home')); ?></a>
    </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.storefront', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/saidur.rahman/Code/velmora/resources/views/errors/429.blade.php ENDPATH**/ ?>