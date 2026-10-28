<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>" dir="<?php echo e(app()->getLocale() === 'ar' ? 'rtl' : 'ltr'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $__env->yieldContent('title', config('app.name')); ?></title>
    <?php echo $__env->yieldPushContent('head'); ?>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="min-h-screen bg-surface text-ink antialiased">
    <a href="#main" class="skip-link"><?php echo e(__('layout.skip_to_content')); ?></a>
    <?php echo $__env->yieldContent('body'); ?>
</body>
</html>
<?php /**PATH /Users/saidur.rahman/Code/velmora/resources/views/layouts/base.blade.php ENDPATH**/ ?>