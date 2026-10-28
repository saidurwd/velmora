<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>" dir="<?php echo e(app()->getLocale() === 'ar' ? 'rtl' : 'ltr'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $__env->yieldContent('title', config('app.name')); ?></title>
    <?php if (isset($component)) { $__componentOriginal5070c68489a54e2d9ad6514157ce8522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5070c68489a54e2d9ad6514157ce8522 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'platform::components.hreflang','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('platform::hreflang'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5070c68489a54e2d9ad6514157ce8522)): ?>
<?php $attributes = $__attributesOriginal5070c68489a54e2d9ad6514157ce8522; ?>
<?php unset($__attributesOriginal5070c68489a54e2d9ad6514157ce8522); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5070c68489a54e2d9ad6514157ce8522)): ?>
<?php $component = $__componentOriginal5070c68489a54e2d9ad6514157ce8522; ?>
<?php unset($__componentOriginal5070c68489a54e2d9ad6514157ce8522); ?>
<?php endif; ?>
    <?php echo $__env->yieldPushContent('head'); ?>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="min-h-screen bg-surface text-ink antialiased">
    <a href="#main" class="skip-link"><?php echo e(__('layout.skip_to_content')); ?></a>
    <?php echo $__env->yieldContent('body'); ?>
</body>
</html>
<?php /**PATH /Users/saidur.rahman/Code/velmora/resources/views/layouts/base.blade.php ENDPATH**/ ?>