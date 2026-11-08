<?php

declare(strict_types=1);

return [
    'menu' => 'Policies', 'title' => 'Policies',
    'intro' => 'The legal pages customers are asked to accept. Choose which page is which, then publish it from the page screen. The first version you publish asks every customer to accept it.',
    'kinds' => ['terms' => 'Terms and conditions', 'privacy' => 'Privacy policy', 'returns' => 'Returns policy', 'shipping' => 'Shipping policy', 'cod' => 'Cash on delivery policy'],
    'asked' => [
        'terms' => 'Asked at sign-up and checkout.', 'privacy' => 'Asked at sign-up and checkout.',
        'returns' => 'Shown as a link only. Customers are not asked to accept it.', 'shipping' => 'Shown as a link only. Customers are not asked to accept it.',
        'cod' => 'Asked at checkout when paying cash on delivery.',
    ],
    'page' => 'Page', 'page_is' => 'This page is the :kind.', 'no_page' => 'No page chosen yet.', 'current' => 'In force', 'not_released' => 'Not published yet.',
    'required_yes' => 'customers had to accept it', 'required_no' => 'minor edit: nobody had to accept again', 'accepted_count' => 'Accepted this version: :count',
    'channels' => ['signup' => 'Sign-up', 'checkout' => 'Checkout', 'account' => 'Account page'],
    'choose' => 'Page to use', 'assign' => 'Use this page', 'change' => 'Change page',
    'acceptance' => ['label' => 'Must customers accept this version?', 'yes' => 'Yes, ask everyone again', 'no' => 'No, it is a minor edit'],
    'legend' => 'Our policies', 'checkbox' => 'I have read and accept the',
    'account' => [
        'title' => 'Policies', 'intro' => 'We have updated our policies. Please read and accept them.', 'nothing' => 'You have accepted our current policies.',
        'accept' => 'Accept', 'accepted' => 'Thank you. Your acceptance is recorded.', 'version' => 'version :number', 'in_force' => 'in force since :date',
        'banner' => 'We updated our policies. Please read and accept them.', 'review' => 'Review',
    ],
    'errors' => [
        'policies_required' => 'Please accept our policies to continue.',
        'policies_changed' => 'A policy changed while you were reading. Please read the new version and accept again.',
        'kind_taken' => 'Another page is already used for this policy.',
        'kind_locked' => 'This page has already been published as a policy, so it cannot be changed or deleted.',
    ],
];
