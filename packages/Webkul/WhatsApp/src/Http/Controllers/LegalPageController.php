<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Public pages (no login) for the Meta App Review: privacy, terms and data deletion.
 */
class LegalPageController extends Controller
{
    public function privacy(): View
    {
        return view('whatsapp::legal.privacy', ['legal' => config('legal')]);
    }

    public function terms(): View
    {
        return view('whatsapp::legal.terms', ['legal' => config('legal')]);
    }

    public function deletion(): View
    {
        return view('whatsapp::legal.deletion', ['legal' => config('legal')]);
    }

    public function deletionStatus(string $code): View
    {
        return view('whatsapp::legal.deletion-status', ['legal' => config('legal'), 'code' => strtoupper($code)]);
    }

    /**
     * The request is always logged; the e-mail to the company is a best effort.
     */
    public function requestDeletion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        Log::info('Solicitação de exclusão de dados (formulário público).', [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'ip' => $request->ip(),
        ]);

        $to = (string) config('legal.email');

        try {
            Mail::raw($this->mailBody($data), function ($message) use ($to, $data) {
                $message->to($to)
                    ->replyTo($data['email'], $data['name'])
                    ->subject('Solicitação de exclusão de dados - '.config('legal.app_name'));
            });
        } catch (\Throwable $e) {
            Log::warning('Solicitação de exclusão de dados: falha ao enviar e-mail.', ['error' => $e->getMessage()]);
        }

        return redirect()->route('legal.deletion')->with('deletion_requested', true);
    }

    protected function mailBody(array $data): string
    {
        return implode("\n", [
            'Nova solicitação de exclusão de dados recebida pelo formulário público.',
            '',
            'Nome: '.$data['name'],
            'E-mail: '.$data['email'],
            'Telefone: '.($data['phone'] ?? '-'),
            'Detalhes: '.($data['details'] ?? '-'),
            '',
            'Data: '.now()->format('d/m/Y H:i'),
            'Prazo de atendimento: até '.config('legal.deletion_days').' dias.',
        ]);
    }
}
