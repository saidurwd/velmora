<?php

declare(strict_types=1);

/* A table cell must stay a table cell: `display:flex` / `grid` on a <td> or <th> takes it out of the table, and its neighbours then overlap it (found on a phone-width staff screen, where Delete could not be clicked). Put the flex layout on a wrapper inside the cell. */

it('never puts flex or grid on a table cell itself', function () {
    $found = [];
    foreach (bladeViews() as $file) {
        foreach (file($file) as $n => $line) {
            if (preg_match('/<(td|th)\b[^>]*\bclass="[^"]*(?<![\w-])(flex|inline-flex|grid|inline-grid|block|inline-block)(?![\w-])/', $line, $m)) {
                $found[] = str_replace(base_path().'/', '', $file).':'.($n + 1).' <'.$m[1].' ... '.$m[2];
            }
        }
    }

    expect($found)->toBe([]);
});
