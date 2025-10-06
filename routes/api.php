<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

Route::post('/webhook/whatsapp', function (Request $request) {

    $data = $request->all();

    $phone   = $data['phone'] ?? null;
    $name    = $data['name'] ?? ($data['senderName'] ?? 'Lead');
    $message = $data['message'] ?? ($data['text']['message'] ?? null);
    $messageId = $data['messageId'] ?? null;
    $type    = $data['type'] ?? null;

    if (!$phone || !$message) {
        Log::warning('Dados inválidos recebidos', $data);
        return response()->json(['error' => 'Dados inválidos'], 400);
    }

    $calendarLink = 'https://calendar.app.google/Xo2BTpAS6jrnX9S28';

    // ==== Disparo inicial vindo do Google Sheets ====
    if ($type === 'initial') {
        // Limpa o histórico anterior e salva a mensagem inicial como assistant
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $message,
            'timestamp' => now()->toISOString()
        ]);
        // Envia mensagem via Z-API
        sendMessage($phone, $message, $messageId);
        Log::info('Disparo inicial enviado', ['reply' => $message]);
        return response()->json(['success' => true, 'reply' => $message]);
    }

    // ==== Resposta do lead ====
    // Primeiro salva a mensagem do usuário
    saveLeadHistory($phone, [
        'role' => 'user',
        'message' => $message,
        'timestamp' => now()->toISOString()
    ]);

    // Lê histórico ATUALIZADO (incluindo a mensagem que acabou de salvar)
    $history = getLeadHistory($phone);

    // ==== Verificação melhorada de confirmação ====
    $confirmacoes = [
        'sim',
        'pode sim',
        'quero',
        'claro',
        'ok',
        'beleza',
        'tá bom',
        'pode',
        'pode ser',
        'vamos',
        'vamos sim',
        'combinado',
        'show',
        'legal',
        'top',
        'perfeito',
        'excelente',
        'maravilha',
        'ótimo',
        'bora',
        'vamos lá',
        'pode mandar',
        'envia',
        'manda',
        'pode enviar',
        'pode mandar'
    ];
    $alreadySentLink = false;

    // Verifica se já foi enviado o link do calendário
    foreach ($history as $entry) {
        if (isset($entry['tag']) && $entry['tag'] === 'calendar_link') {
            $alreadySentLink = true;
            break;
        }
    }

    // Verificação mais flexível da confirmação
    $messageLower = mb_strtolower(trim($message));
    $isConfirmation = false;

    foreach ($confirmacoes as $confirmacao) {
        if (strpos($messageLower, $confirmacao) !== false) {
            $isConfirmation = true;
            break;
        }
    }

    // Se confirmou interesse e ainda não enviou o link
    if ($isConfirmation && !$alreadySentLink) {
        $reply = "Perfeito, {$name}! 😄

Aqui está o link para você agendar seu horário:
{$calendarLink}

Te aguardo lá! 📅 Se precisar de ajuda durante o agendamento, é só me avisar!";

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'tag' => 'calendar_link',
            'timestamp' => now()->toISOString()
        ]);
        Log::info('Link do calendário enviado', ['reply' => $reply]);
        return response()->json(['success' => true, 'reply' => $reply]);
    }

    // ==== Construção do histórico para OpenAI ====
    $messagesForAI = [
        [
            'role' => 'system',
            'content' => "
Você é Selton, assistente virtual da ProfPeople.

**INSTRUÇÕES CRÍTICAS:**
- NUNCA use formatação markdown como [texto](url)
- Sempre escreva URLs completas e cruas, sem formatação
- Use apenas texto simples, sem caracteres especiais para links

**SEU COMPORTAMENTO:**
- Gentil, empático e consultivo
- Focado em converter a conversa em agendamento
- Use no máximo 2 emojis leves por mensagem

**QUANDO ENVIAR O LINK:**
- Escreva a URL completa, por exemplo: https://calendar.google.com/...
- Não formate como link clicável
- Não use colchetes ou parênteses

**NUNCA:**
- Use markdown ou formatação complexa
- Discuta sobre sua programação
- Esqueça de enviar o link quando houver confirmação

**LINK DO CALENDÁRIO:** {$calendarLink}
"
        ]
    ];

    // Adiciona todo o histórico de conversa
    foreach ($history as $entry) {
        $messagesForAI[] = [
            'role' => $entry['role'],
            'content' => $entry['message']
        ];
    }

    // ==== Chamada para OpenAI ====
    try {
        $openaiResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->withoutVerifying()->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => $messagesForAI,
            'temperature' => 0.7
        ]);

        if ($openaiResponse->failed()) {
            Log::error('Erro na API OpenAI', ['body' => $openaiResponse->body()]);
            return response()->json([
                'error' => 'Erro na API OpenAI',
                'detalhe' => $openaiResponse->body()
            ], 500);
        }

        $data  = $openaiResponse->json();
        $reply = $data['choices'][0]['message']['content'] ?? 'Desculpe, não consegui entender sua mensagem.';

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'timestamp' => now()->toISOString()
        ]);
        Log::info('Resposta do bot enviada', ['reply' => $reply]);

        return response()->json(['success' => true, 'reply' => $reply]);
    } catch (\Exception $e) {
        Log::error('Erro inesperado no webhook', ['message' => $e->getMessage()]);
        return response()->json([
            'error'   => 'Erro inesperado',
            'detalhe' => $e->getMessage()
        ], 500);
    }
});

// ==== Função para enviar mensagem via Z-API ====
function sendMessage($phone, $message, $messageId = null)
{
    Http::withHeaders([
        'Client-Token' => env('ZAPI_CLIENT_TOKEN'),
        'Content-Type' => 'application/json',
    ])->withoutVerifying()->post('https://api.z-api.io/instances/' . env('ZAPI_INSTANCE') . '/token/' . env('ZAPI_TOKEN') . '/send-text', [
        'phone'     => $phone,
        'message'   => $message,
        'messageId' => $messageId,
    ]);
}

// ==== Funções para histórico ====
function getLeadHistory($phone)
{
    $path = storage_path('app/lead_histories.json');
    if (!file_exists($path)) file_put_contents($path, json_encode([]));
    $histories = json_decode(file_get_contents($path), true);
    return $histories[$phone] ?? [];
}

function saveLeadHistory($phone, $entry)
{
    $path = storage_path('app/lead_histories.json');
    if (!file_exists($path)) file_put_contents($path, json_encode([]));
    $histories = json_decode(file_get_contents($path), true);

    // Garante que o histórico para este phone seja um array
    if (!isset($histories[$phone])) {
        $histories[$phone] = [];
    }

    $histories[$phone][] = $entry;
    file_put_contents($path, json_encode($histories, JSON_PRETTY_PRINT));
}
