<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Actions\SaveNotificationTemplate;
use App\Modules\Notifications\Exceptions\NotificationException;
use App\Modules\Notifications\Http\Concerns\ActsAsStaff;
use App\Modules\Notifications\Http\Requests\SaveTemplateRequest;
use App\Modules\Notifications\Services\NotificationReader;
use App\Modules\Notifications\Services\TemplateRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff edit the words of the messages the shop sends, per language, and see a preview with sample values before saving. */
class TemplateController
{
    use ActsAsStaff;

    public function __construct(private readonly NotificationReader $reader) {}

    public function index(): View
    {
        return view('notifications::admin.templates', ['rows' => $this->reader->templates(), 'health' => $this->reader->health()]);
    }

    public function edit(Request $request, string $code): View
    {
        $data = $this->reader->template($code) ?? abort(404);

        return view('notifications::admin.template-form', ['t' => $data, 'code' => $code, 'can' => $request->user('admin')?->can('notifications.manage') === true, 'preview' => null]);
    }

    public function save(SaveTemplateRequest $request, SaveNotificationTemplate $save, string $code): RedirectResponse
    {
        try {
            $save->handle($this->actor($request), $code, (string) $request->validated('locale'), $request->validated('subject'), (string) $request->validated('body'), $request->boolean('active'));
        } catch (NotificationException $e) {
            return back()->withErrors(['template' => __('notifications::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.notifications.templates.edit', ['code' => $code])->with('status', __('notifications::admin.saved'));
    }

    /** Shows what the words would look like with sample values; nothing is saved. */
    public function preview(SaveTemplateRequest $request, TemplateRenderer $renderer, string $code): View
    {
        $data = $this->reader->template($code) ?? abort(404);
        $definition = $data['definition'];
        $sample = $definition->sample;
        $text = (string) $request->validated('body');
        $subject = (string) $request->validated('subject');
        $problem = null;
        foreach (array_filter([$text, $subject], fn ($t) => $t !== '') as $t) {
            if ($renderer->hasStrayBraces($t)) {
                $problem = __('notifications::errors.braces_invalid');
            } elseif (($unknown = array_values(array_diff($renderer->names($t), $definition->variables))) !== []) {
                $problem = __('notifications::errors.variable_unknown', ['name' => $unknown[0]]);
            }
        }

        return view('notifications::admin.template-form', [
            't' => $data, 'code' => $code, 'can' => true, 'preview' => ['locale' => (string) $request->validated('locale'), 'problem' => $problem,
                'subject' => $definition->channel === 'email' ? $renderer->singleLine($renderer->render($subject, $sample)) : null, 'body' => $renderer->render($text, $sample)],
        ]);
    }
}
