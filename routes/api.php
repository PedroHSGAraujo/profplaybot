<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

// normaliza numero de telefone para evitar problemas (tira parenteses, espaços, hífens e adiciona o 55 no começo caso o número não tenha)
function normalizePhone($phone)
{
    // Remove tudo que não é número
    $phone = preg_replace('/[^\d]/', '', $phone);

    Log::info('Normalizando telefone', [
        'original' => $phone,
        'length' => strlen($phone)
    ]);

    // Se não começa com 55, adiciona
    if (!str_starts_with($phone, '55')) {
        $phone = '55' . $phone;
    }

    Log::info('Telefone normalizado', [
        'normalized' => $phone,
        'length' => strlen($phone)
    ]);

    return $phone;
}

// envio de mensagens
Route::post('/webhook/whatsapp', function (Request $request) {

    $data = $request->all();

    $phone = $data['phone'] ?? null;
    $name = $data['name'] ?? ($data['senderName'] ?? 'Lead');
    $message = $data['message'] ?? ($data['text']['message'] ?? null);
    $messageId = $data['messageId'] ?? null;
    $type = $data['type'] ?? null;

    if (!$phone || !$message) {
        Log::warning('Dados inválidos recebidos', $data);
        return response()->json(['error' => 'Dados inválidos'], 400);
    }

    $calendarLink = 'https://app.simplymeet.me/profplay/profplay-60-1761248599757';

    // 🔥 CORREÇÃO CRÍTICA: SEMPRE buscar o telefone correto pelo nome
    $correctPhone = findPhoneByName($name);
    if ($correctPhone) {
        Log::info('🔁 CORRIGINDO TELEFONE - Usando telefone do mapeamento', [
            'phone_recebido' => $phone,
            'phone_correto' => $correctPhone,
            'name' => $name
        ]);
        $phone = $correctPhone;
    } else {
        Log::warning('Telefone correto não encontrado pelo nome', [
            'name' => $name,
            'phone_recebido' => $phone
        ]);
    }

    // associa o nome ao telefone ao responder o formulário
    if ($type === 'initial') {
        savePhoneNameMapping($phone, $name);

        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $message,
            'timestamp' => now()->toISOString()
        ]);
        sendMessage($phone, $message, $messageId);
        Log::info('Disparo inicial enviado', ['phone' => $phone, 'name' => $name]);
        return response()->json(['success' => true, 'reply' => $message]);
    }

    // lê a resposta do lead
    saveLeadHistory($phone, [
        'role' => 'user',
        'message' => $message,
        'timestamp' => now()->toISOString()
    ]);

    $history = getLeadHistory($phone);

    // === VERIFICAÇÃO SIMPLIFICADA ===
    Log::info('🔍 Verificando estado do lead', [
        'phone' => $phone,
        'name' => $name,
        'has_scheduled_meeting' => hasScheduledMeeting($phone)
    ]);

    // Primeiro verifica se está esperando resposta sobre lembretes
    if (isWaitingReminderResponse($phone)) {
        Log::info('🎯 Estado detectado: AGUARDANDO RESPOSTA DE LEMBRETES');
        return handleReminderPermissionFinal($phone, $name, $message);
    }

    // Se já agendou, vai para conversa normal
    if (hasScheduledMeeting($phone)) {
        Log::info('📅 Lead já agendou - conversa normal');
        return handleNormalConversation($phone, $name, $message, $history);
    }

    // Se ainda não agendou - lógica normal
    Log::info('🤔 Lead ainda não agendou - verificando confirmação');
    return handlePreScheduling($phone, $name, $message, $history, $calendarLink);
});

function isWaitingReminderResponse($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/waiting_reminder_response.json');
    
    if (!file_exists($path)) {
        Log::info('Arquivo de espera por lembretes não existe', ['phone' => $phone]);
        return false;
    }
    
    $waiting = json_decode(file_get_contents($path), true) ?? [];
    $result = isset($waiting[$phone]) && $waiting[$phone] === true;
    
    Log::info('📋 Verificação de espera por lembretes', [
        'phone' => $phone,
        'result' => $result,
        'waiting_phones' => array_keys($waiting)
    ]);
    
    return $result;
}

function debugWaitingReminderFile()
{
    $path = storage_path('app/waiting_reminder_response.json');
    if (!file_exists($path)) {
        Log::info('DEBUG: Arquivo waiting_reminder_response.json NÃO EXISTE');
        return;
    }
    
    $content = file_get_contents($path);
    $data = json_decode($content, true) ?? [];
    
    Log::info('DEBUG: Conteúdo do arquivo waiting_reminder_response.json', [
        'content' => $content,
        'decoded' => $data,
        'count' => count($data)
    ]);
}

function setWaitingReminderResponse($phone, $waiting)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/waiting_reminder_response.json');

    if (!file_exists($path)) {
        file_put_contents($path, json_encode([]));
    }

    $waitingStates = json_decode(file_get_contents($path), true);

    if ($waiting) {
        $waitingStates[$phone] = true;
    } else {
        unset($waitingStates[$phone]);
    }

    file_put_contents($path, json_encode($waitingStates, JSON_PRETTY_PRINT));

    Log::info('Estado de espera por lembretes definido', [
        'phone' => $phone,
        'waiting' => $waiting
    ]);
}

function handleReminderPermissionFinal($phone, $name, $message)
{
    $messageLower = mb_strtolower(trim($message));

    Log::info('Processando resposta FINAL de lembretes', [
        'phone' => $phone,
        'message' => $message,
        'message_lower' => $messageLower
    ]);

    $positiveResponses = ['sim', 'quero', 'claro', 'ok', 'pode', 'sim por favor', 'pode sim', 'beleza', 'aceito', 'pode enviar'];
    $negativeResponses = ['não', 'nao', 'não quero', 'nao quero', 'não precisa', 'nao precisa', 'não obrigado', 'nao obrigado'];

    $acceptedReminders = false;
    foreach ($positiveResponses as $response) {
        if (strpos($messageLower, $response) !== false) {
            $acceptedReminders = true;
            break;
        }
    }

    $rejectedReminders = false;
    foreach ($negativeResponses as $response) {
        if (strpos($messageLower, $response) !== false) {
            $rejectedReminders = true;
            break;
        }
    }

    if ($acceptedReminders) {
        Log::info('✅ Lead ACEITOU lembretes - processando', ['phone' => $phone]);

        saveReminderPermission($phone, true);

        // Agenda os lembretes
        $meetingData = getMeetingSchedule($phone);
        if ($meetingData) {
            $meetingDateTime = Carbon::parse($meetingData['meeting_datetime'])->setTimezone('America/Sao_Paulo');

            Log::info('📅 Agendando lembretes para reunião', [
                'phone' => $phone,
                'meeting_date' => $meetingDateTime->format('d/m/Y H:i:s')
            ]);

            $remindersCount = scheduleRemindersFinal($phone, $meetingData['name'], $meetingDateTime);
            Log::info('✅ Lembretes agendados', ['count' => $remindersCount]);
        }

        $reply = "Ótimo, {$name}! 😊 Vou te enviar lembretes antes da reunião para você não esquecer. Até lá!";

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'tag' => 'reminder_permission_accepted',
            'timestamp' => now()->toISOString()
        ]);

        // LIMPA o estado de espera
        setWaitingReminderResponse($phone, false);

        Log::info('✅ Permissão de lembretes FINALIZADA com sucesso', ['phone' => $phone]);
        return response()->json(['success' => true, 'reply' => $reply]);

    } elseif ($rejectedReminders) {
        Log::info('❌ Lead RECUSOU lembretes', ['phone' => $phone]);

        saveReminderPermission($phone, false);

        $reply = "Sem problemas, {$name}! Não vou enviar lembretes. Mas fique tranquilo(a), sua reunião está confirmada. Até lá! 😊";

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'tag' => 'reminder_permission_denied',
            'timestamp' => now()->toISOString()
        ]);

        // LIMPA o estado de espera
        setWaitingReminderResponse($phone, false);

        Log::info('✅ Permissão negada FINALIZADA', ['phone' => $phone]);
        return response()->json(['success' => true, 'reply' => $reply]);
    }

    // Se não foi uma resposta clara, pergunta novamente MAS mantém o estado
    Log::info('❓ Resposta não clara sobre lembretes', ['phone' => $phone, 'message' => $message]);

    $reply = "Desculpe, {$name}, não entendi. Você gostaria que eu envie lembretes antes da sua reunião? (Responda 'sim' ou 'não')";

    sendMessage($phone, $reply);
    saveLeadHistory($phone, [
        'role' => 'assistant',
        'message' => $reply,
        'timestamp' => now()->toISOString()
    ]);

    // MANTÉM o estado de espera
    return response()->json(['success' => true, 'reply' => $reply]);
}

function scheduleRemindersFinal($phone, $name, Carbon $meetingDateTime)
{
    $phone = normalizePhone($phone);
    $reminders = [];

    $reminderIntervals = [
        ['type' => '24h', 'hours_before' => 24],
        ['type' => '3h', 'hours_before' => 3],
        ['type' => '1h', 'hours_before' => 1],
        ['type' => '30min', 'hours_before' => 0.5],
        ['type' => 'start', 'hours_before' => 0], // NOVO: no horário da reunião
    ];

    foreach ($reminderIntervals as $interval) {
        $sendAt = $meetingDateTime->copy()->subHours($interval['hours_before']);

        if ($sendAt->isFuture() || $interval['type'] === 'start') {
            $reminders[] = [
                'phone' => $phone,
                'name' => $name,
                'meeting_datetime' => $meetingDateTime->toISOString(),
                'type' => $interval['type'],
                'send_at' => $sendAt->toISOString(),
                'send_at_local' => $sendAt->format('d/m/Y H:i:s'),
                'created_at' => now()->toISOString()
            ];
        }
    }

    $path = storage_path('app/reminders.json');
    
    if (!file_exists($path)) {
        file_put_contents($path, json_encode([]));
    }
    
    $allReminders = json_decode(file_get_contents($path), true) ?? [];
    $allReminders = array_merge($allReminders, $reminders);
    
    $result = file_put_contents($path, json_encode($allReminders, JSON_PRETTY_PRINT));

    Log::info('💾 Lembretes salvos no arquivo', [
        'phone' => $phone,
        'reminders_count' => count($reminders),
        'total_reminders_in_file' => count($allReminders),
        'file_write_success' => ($result !== false),
        'reminder_types' => array_column($reminders, 'type')
    ]);

    return count($reminders);
}

// ===== NOVAS FUNÇÕES PARA ORGANIZAR O FLUXO =====

function debugPhoneNormalization($phone)
{
    $normalized = normalizePhone($phone);
    Log::info('DEBUG Phone Normalization', [
        'input' => $phone,
        'output' => $normalized,
        'length_input' => strlen($phone),
        'length_output' => strlen($normalized)
    ]);
    return $normalized;
}
function handleReminderPermission($phone, $name, $message)
{
    $messageLower = mb_strtolower(trim($message));

    Log::info('Processando resposta de lembretes', [
        'phone' => $phone,
        'message' => $message,
        'message_lower' => $messageLower
    ]);

    $positiveResponses = ['sim', 'quero', 'claro', 'ok', 'pode', 'sim por favor', 'pode sim', 'beleza', 'aceito', 'pode enviar'];
    $negativeResponses = ['não', 'nao', 'não quero', 'nao quero', 'não precisa', 'nao precisa', 'não obrigado', 'nao obrigado'];

    $acceptedReminders = false;
    foreach ($positiveResponses as $response) {
        if (strpos($messageLower, $response) !== false) {
            $acceptedReminders = true;
            break;
        }
    }

    $rejectedReminders = false;
    foreach ($negativeResponses as $response) {
        if (strpos($messageLower, $response) !== false) {
            $rejectedReminders = true;
            break;
        }
    }

    if ($acceptedReminders) {
        Log::info('Lead ACEITOU lembretes', ['phone' => $phone]);

        saveReminderPermission($phone, true);

        // Agenda os lembretes
        $meetingData = getMeetingSchedule($phone);
        if ($meetingData) {
            $meetingDateTime = Carbon::parse($meetingData['meeting_datetime'])->setTimezone('America/Sao_Paulo');

            Log::info('Agendando lembretes para reunião', [
                'phone' => $phone,
                'meeting_date' => $meetingDateTime->format('d/m/Y H:i:s')
            ]);

            scheduleReminders($phone, $meetingData['name'], $meetingDateTime);

            // VERIFICA se os lembretes foram salvos
            $pendingReminders = getPendingReminders();
            Log::info('Lembretes pendentes após agendamento', [
                'total' => count($pendingReminders),
                'reminders' => $pendingReminders
            ]);
        }

        $reply = "Ótimo, {$name}! 😊 Vou te enviar lembretes antes da reunião para você não esquecer. Até lá!";

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'tag' => 'reminder_permission_accepted',
            'timestamp' => now()->toISOString()
        ]);

        Log::info('Permissão de lembretes processada com sucesso', ['phone' => $phone]);
        return response()->json(['success' => true, 'reply' => $reply]);

    } elseif ($rejectedReminders) {
        Log::info('Lead RECUSOU lembretes', ['phone' => $phone]);

        saveReminderPermission($phone, false);

        $reply = "Sem problemas, {$name}! Não vou enviar lembretes. Mas fique tranquilo(a), sua reunião está confirmada. Até lá! 😊";

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'tag' => 'reminder_permission_denied',
            'timestamp' => now()->toISOString()
        ]);

        Log::info('Permissão de lembretes negada processada', ['phone' => $phone]);
        return response()->json(['success' => true, 'reply' => $reply]);
    }

    // Se não foi uma resposta clara, pergunta novamente
    Log::info('Resposta não clara sobre lembretes', ['phone' => $phone, 'message' => $message]);

    $reply = "Desculpe, {$name}, não entendi. Você gostaria que eu envie lembretes antes da sua reunião? (Responda 'sim' ou 'não')";

    sendMessage($phone, $reply);
    saveLeadHistory($phone, [
        'role' => 'assistant',
        'message' => $reply,
        'timestamp' => now()->toISOString()
    ]);

    return response()->json(['success' => true, 'reply' => $reply]);
}

function handleReminderPermissionResponse($phone, $name, $message)
{
    $messageLower = mb_strtolower(trim($message));
    $positiveResponses = ['sim', 'quero', 'claro', 'ok', 'pode', 'sim por favor', 'pode sim', 'beleza', 'aceito'];
    $negativeResponses = ['não', 'nao', 'não quero', 'nao quero', 'não precisa', 'nao precisa'];

    $acceptedReminders = false;
    foreach ($positiveResponses as $response) {
        if (strpos($messageLower, $response) !== false) {
            $acceptedReminders = true;
            break;
        }
    }

    $rejectedReminders = false;
    foreach ($negativeResponses as $response) {
        if (strpos($messageLower, $response) !== false) {
            $rejectedReminders = true;
            break;
        }
    }

    if ($acceptedReminders) {
        saveReminderPermission($phone, true);

        // Agenda os lembretes
        $meetingData = getMeetingSchedule($phone);
        if ($meetingData) {
            $meetingDateTime = Carbon::parse($meetingData['meeting_datetime'])->setTimezone('America/Sao_Paulo');
            scheduleReminders($phone, $meetingData['name'], $meetingDateTime);

            Log::info('Lembretes agendados com sucesso', [
                'phone' => $phone,
                'meeting_date' => $meetingDateTime->format('d/m/Y H:i:s'),
                'reminders_count' => 3
            ]);
        }

        $reply = "Ótimo, {$name}! 😊 Vou te enviar lembretes antes da reunião para você não esquecer. Até lá!";

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'tag' => 'reminder_permission_accepted',
            'timestamp' => now()->toISOString()
        ]);

        // Limpa o estado
        clearCurrentState($phone);

        Log::info('Permissão de lembretes concedida e processada', ['phone' => $phone]);
        return response()->json(['success' => true, 'reply' => $reply]);

    } elseif ($rejectedReminders) {
        saveReminderPermission($phone, false);

        $reply = "Sem problemas, {$name}! Não vou enviar lembretes. Mas fique tranquilo(a), sua reunião está confirmada. Até lá! 😊";

        sendMessage($phone, $reply);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $reply,
            'tag' => 'reminder_permission_denied',
            'timestamp' => now()->toISOString()
        ]);

        // Limpa o estado
        clearCurrentState($phone);

        Log::info('Permissão de lembretes negada', ['phone' => $phone]);
        return response()->json(['success' => true, 'reply' => $reply]);
    }

    // Se não foi uma resposta clara, pergunta novamente
    $reply = "Desculpe, {$name}, não entendi. Você gostaria que eu envie lembretes antes da sua reunião? (Responda 'sim' ou 'não')";

    sendMessage($phone, $reply);
    saveLeadHistory($phone, [
        'role' => 'assistant',
        'message' => $reply,
        'timestamp' => now()->toISOString()
    ]);

    return response()->json(['success' => true, 'reply' => $reply]);
}

function handlePreScheduling($phone, $name, $message, $history, $calendarLink)
{
    // possíveis confirmações
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
        'pode enviar'
    ];

    $alreadySentLink = false;

    foreach ($history as $entry) {
        if (isset($entry['tag']) && $entry['tag'] === 'calendar_link') {
            $alreadySentLink = true;
            break;
        }
    }

    $messageLower = mb_strtolower(trim($message));
    $isConfirmation = false;

    foreach ($confirmacoes as $confirmacao) {
        if (strpos($messageLower, $confirmacao) !== false) {
            $isConfirmation = true;
            break;
        }
    }

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

        // agenda followups
        Log::info('Tentando agendar follow-ups', ['phone' => $phone, 'name' => $name]);
        try {
            scheduleFollowUps($phone, $name);
            Log::info('Follow-ups agendados com sucesso', ['phone' => $phone]);
        } catch (\Exception $e) {
            Log::error('Erro ao agendar follow-ups', [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        Log::info('Link do calendário enviado', ['reply' => $reply]);
        return response()->json(['success' => true, 'reply' => $reply]);
    }

    // Se não for confirmação, usa OpenAI
    return handleNormalConversation($phone, $name, $message, $history);
}

function handleNormalConversation($phone, $name, $message, $history)
{
    $calendarLink = 'https://app.simplymeet.me/profplay/profplay-60-1761248599757';

    // construção do histórico para OpenAI
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

    foreach ($history as $entry) {
        $messagesForAI[] = [
            'role' => $entry['role'],
            'content' => $entry['message']
        ];
    }

    // chama OpenAi
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

        $data = $openaiResponse->json();
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
            'error' => 'Erro inesperado',
            'detalhe' => $e->getMessage()
        ], 500);
    }
}

// ===== FUNÇÕES DE ESTADO =====

function getCurrentState($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/lead_states.json');

    if (!file_exists($path)) {
        return null;
    }

    $states = json_decode(file_get_contents($path), true);
    return $states[$phone] ?? null;
}

function setCurrentState($phone, $state)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/lead_states.json');

    if (!file_exists($path)) {
        file_put_contents($path, json_encode([]));
    }

    $states = json_decode(file_get_contents($path), true);
    $states[$phone] = $state;

    file_put_contents($path, json_encode($states, JSON_PRETTY_PRINT));
    Log::info('Estado do lead definido', ['phone' => $phone, 'state' => $state]);
}

function clearCurrentState($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/lead_states.json');

    if (!file_exists($path)) {
        return;
    }

    $states = json_decode(file_get_contents($path), true);
    if (isset($states[$phone])) {
        unset($states[$phone]);
        file_put_contents($path, json_encode($states, JSON_PRETTY_PRINT));
        Log::info('Estado do lead limpo', ['phone' => $phone]);
    }
}


// rota para receber a confirmação do agendamento
Route::post('/webhook/meeting-scheduled', function (Request $request) {
    $data = $request->all();

    $name = $data['name'] ?? null;
    $phone = $data['phone'] ?? null;
    $email = $data['email'] ?? null;
    $meetingDate = $data['meeting_date'] ?? null;
    $meetLink = $data['meet_link'] ?? null; // NOVO: link do Google Meet

    if (!$name || !$meetingDate) {
        Log::warning('Dados de agendamento inválidos', $data);
        return response()->json(['error' => 'Nome e data são obrigatórios'], 400);
    }

    try {
        // busca o telefone pelo nome
        $phone = findPhoneByName($name);

        if (!$phone) {
            Log::warning('Telefone não encontrado para o nome', [
                'name' => $name,
                'email' => $email
            ]);
            return response()->json([
                'error' => 'Telefone não encontrado',
                'message' => 'Não foi possível encontrar o telefone vinculado ao nome: ' . $name,
                'suggestion' => 'Verifique se o nome no Simply Meet é exatamente igual ao do formulário'
            ], 404);
        }

        Log::info('📞 Telefone encontrado para agendamento', [
            'name' => $name,
            'phone' => $phone,
            'meet_link_received' => !empty($meetLink) // Log se recebeu o link
        ]);

        // formata a data
        $meetingDateTime = Carbon::parse($meetingDate)->setTimezone('America/Sao_Paulo');

        // cancela os follow-ups
        Log::info('🔄 Cancelando follow-ups', ['phone' => $phone, 'name' => $name]);
        cancelFollowUps($phone);
        cancelFollowUpsByName($name);

        // salva o agendamento INCLUINDO o link do Meet
        saveMeetingSchedule($phone, [
            'name' => $name,
            'email' => $email,
            'meeting_datetime' => $meetingDateTime->toISOString(),
            'meet_link' => $meetLink,
            'scheduled_at' => now()->toISOString(),
            'status' => 'scheduled'
        ]);

        debugMeetingSchedules();

        // DEFINE O ESTADO DE ESPERA ANTES de enviar a mensagem
        setWaitingReminderResponse($phone, true);
        Log::info('🎯 Estado de espera por lembretes DEFINIDO', ['phone' => $phone]);

        // envia confirmação e pergunta sobre lembretes
        $confirmationMessage = "🎉 Reunião confirmada, {$name}!\n\n📅 Data: " . $meetingDateTime->format('d/m/Y') . "\n🕐 Horário: " . $meetingDateTime->format('H:i') . "\n\nPosso te enviar lembretes para você não esquecer? 😊";

        sendMessage($phone, $confirmationMessage);
        saveLeadHistory($phone, [
            'role' => 'assistant',
            'message' => $confirmationMessage,
            'tag' => 'meeting_confirmation',
            'timestamp' => now()->toISOString()
        ]);

        Log::info('✅ Agendamento registrado e pergunta sobre lembretes enviada', [
            'phone' => $phone,
            'name' => $name,
            'meeting_date' => $meetingDateTime->toISOString(),
            'meet_link_saved' => !empty($meetLink)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Agendamento registrado com sucesso',
            'phone_found' => $phone,
            'waiting_reminder_response' => true,
            'meet_link_received' => !empty($meetLink)
        ]);

    } catch (\Exception $e) {
        Log::error('Erro ao processar agendamento', [
            'message' => $e->getMessage(),
            'data' => $data
        ]);
        return response()->json([
            'error' => 'Erro ao processar agendamento',
            'detalhe' => $e->getMessage()
        ], 500);
    }
});

// rota de lembretes (chamada pelo cron a cada minuto)
Route::get('/process-reminders', function () {
    $reminders = getPendingReminders();
    $processed = 0;
    $now = now();

    Log::info('🕒 Processando lembretes', [
        'total_reminders' => count($reminders),
        'current_time' => $now->format('d/m/Y H:i:s'),
        'current_time_utc' => $now->toISOString()
    ]);

    foreach ($reminders as $index => $reminder) {
        $sendAt = Carbon::parse($reminder['send_at']);
        $phone = $reminder['phone'];
        $name = $reminder['name'];
        $meetingDateTime = Carbon::parse($reminder['meeting_datetime'])->setTimezone('America/Sao_Paulo');
        $type = $reminder['type'];

        Log::info('🔍 Verificando lembrete', [
            'type' => $type,
            'phone' => $phone,
            'send_at_utc' => $sendAt->toISOString(),
            'send_at_local' => $sendAt->format('d/m/Y H:i:s'),
            'now_local' => $now->format('d/m/Y H:i:s'),
            'is_past' => $sendAt->isPast(),
            'should_send' => $sendAt->isPast()
        ]);

        if ($sendAt->isPast()) {
            if (hasReminderPermission($phone)) {
                // 🔥 CORREÇÃO: Passar o phone como quarto parâmetro
                $message = getReminderMessage($type, $name, $meetingDateTime, $phone);

                sendMessage($phone, $message);
                saveLeadHistory($phone, [
                    'role' => 'assistant',
                    'message' => $message,
                    'tag' => 'reminder_' . $type,
                    'timestamp' => now()->toISOString()
                ]);

                Log::info('✅ Lembrete enviado', [
                    'phone' => $phone,
                    'type' => $type,
                    'sent_at' => $now->format('d/m/Y H:i:s'),
                    'message_preview' => substr($message, 0, 100) . '...'
                ]);
            } else {
                Log::info('🚫 Lembrete ignorado - sem permissão', [
                    'phone' => $phone,
                    'type' => $type
                ]);
            }

            removeReminder($index);
            $processed++;
        } else {
            Log::info('⏰ Lembrete aguardando horário correto', [
                'phone' => $phone,
                'type' => $type,
                'send_at' => $sendAt->format('d/m/Y H:i:s'),
                'time_until_send' => $now->diffForHumans($sendAt)
            ]);
        }
    }

    return response()->json([
        'success' => true,
        'processed' => $processed,
        'pending' => count(getPendingReminders()),
        'current_time' => $now->format('d/m/Y H:i:s')
    ]);
});

function debugMeetingSchedules()
{
    $path = storage_path('app/meeting_schedules.json');
    if (!file_exists($path)) {
        Log::info('DEBUG: Arquivo meeting_schedules.json NÃO EXISTE');
        return;
    }
    
    $content = file_get_contents($path);
    $data = json_decode($content, true) ?? [];
    
    Log::info('DEBUG: Conteúdo do meeting_schedules.json', [
        'content' => $content,
        'decoded' => $data,
        'count' => count($data)
    ]);
}

// mesma lógica da rota de reminders
Route::get('/process-followups', function () {
    $followups = getPendingFollowUps();
    $processed = 0;
    $skipped = 0;
    $now = now();

    Log::info('Processando follow-ups', [
        'total_followups' => count($followups),
        'current_time' => $now->format('d/m/Y H:i:s')
    ]);

    foreach ($followups as $index => $followup) {
        $sendAt = Carbon::parse($followup['send_at']);
        $phone = $followup['phone'];
        $name = $followup['name'];
        $type = $followup['type'];

        // verifica se o lead já agendou (mesmo antes do horário)
        if (hasScheduledMeeting($phone)) {
            Log::info('Follow-up cancelado - lead já agendou', [
                'phone' => $phone,
                'type' => $type
            ]);
            removeFollowUp($index);
            $skipped++;
            continue;
        }

        // se chegou a hora de enviar
        if ($sendAt->isPast()) {
            $message = getFollowUpMessage($type, $name);

            sendMessage($phone, $message);
            saveLeadHistory($phone, [
                'role' => 'assistant',
                'message' => $message,
                'tag' => 'followup_' . $type,
                'timestamp' => now()->toISOString()
            ]);

            removeFollowUp($index);
            $processed++;

            Log::info('Follow-up enviado', [
                'phone' => $phone,
                'type' => $type,
                'sent_at' => $now->format('d/m/Y H:i:s')
            ]);
        }
    }

    return response()->json([
        'success' => true,
        'processed' => $processed,
        'skipped' => $skipped,
        'pending' => count(getPendingFollowUps()),
        'current_time' => $now->format('d/m/Y H:i:s')
    ]);
});

// funções
function sendMessage($phone, $message, $messageId = null)
{
    Http::withHeaders([
        'Client-Token' => env('ZAPI_CLIENT_TOKEN'),
        'Content-Type' => 'application/json',
    ])->withoutVerifying()->post('https://api.z-api.io/instances/' . env('ZAPI_INSTANCE') . '/token/' . env('ZAPI_TOKEN') . '/send-text', [
                'phone' => $phone,
                'message' => $message,
                'messageId' => $messageId,
            ]);
}

function getLeadHistory($phone)
{
    $path = storage_path('app/lead_histories.json');
    if (!file_exists($path))
        file_put_contents($path, json_encode([]));
    $histories = json_decode(file_get_contents($path), true);
    return $histories[$phone] ?? [];
}

function saveLeadHistory($phone, $entry)
{
    $path = storage_path('app/lead_histories.json');
    if (!file_exists($path))
        file_put_contents($path, json_encode([]));
    $histories = json_decode(file_get_contents($path), true);

    if (!isset($histories[$phone])) {
        $histories[$phone] = [];
    }

    $histories[$phone][] = $entry;
    file_put_contents($path, json_encode($histories, JSON_PRETTY_PRINT));
}

function savePhoneNameMapping($phone, $name)
{
    $path = storage_path('app/phone_name_mappings.json');
    if (!file_exists($path))
        file_put_contents($path, json_encode([]));

    $mappings = json_decode(file_get_contents($path), true);

    $normalizedName = mb_strtolower(trim($name));
    $normalizedPhone = normalizePhone($phone);

    $mappings[$normalizedName] = [
        'phone' => $normalizedPhone,
        'original_name' => $name,
        'created_at' => now()->toISOString()
    ];

    file_put_contents($path, json_encode($mappings, JSON_PRETTY_PRINT));

    Log::info('Mapeamento salvo', [
        'phone' => $phone,
        'normalized_phone' => $normalizedPhone,
        'name' => $name,
        'normalized_name' => $normalizedName
    ]);
}

function findPhoneByName($name)
{
    $path = storage_path('app/phone_name_mappings.json');
    if (!file_exists($path))
        return null;

    $mappings = json_decode(file_get_contents($path), true);
    $normalizedName = mb_strtolower(trim($name));

    if (isset($mappings[$normalizedName])) {
        Log::info('Telefone encontrado (match exato)', [
            'name' => $name,
            'phone' => $mappings[$normalizedName]['phone']
        ]);
        return $mappings[$normalizedName]['phone'];
    }

    foreach ($mappings as $savedName => $data) {
        if (strpos($normalizedName, $savedName) !== false || strpos($savedName, $normalizedName) !== false) {
            Log::info('Telefone encontrado (match parcial)', [
                'searched_name' => $name,
                'found_name' => $data['original_name'],
                'phone' => $data['phone']
            ]);
            return $data['phone'];
        }
    }

    Log::warning('Telefone não encontrado', [
        'searched_name' => $name,
        'normalized_name' => $normalizedName,
        'available_mappings' => array_keys($mappings)
    ]);

    return null;
}

function saveMeetingSchedule($phone, $data)
{
    $path = storage_path('app/meeting_schedules.json');
    if (!file_exists($path))
        file_put_contents($path, json_encode([]));
    $schedules = json_decode(file_get_contents($path), true);
    $schedules[$phone] = $data;
    file_put_contents($path, json_encode($schedules, JSON_PRETTY_PRINT));
}

// funçoes para reminders
function scheduleReminders($phone, $name, Carbon $meetingDateTime)
{
    $originalPhone = $phone;
    $phone = normalizePhone($phone);

    Log::info('Agendando lembretes', [
        'original_phone' => $originalPhone,
        'normalized_phone' => $phone,
        'name' => $name,
        'meeting_date' => $meetingDateTime->format('d/m/Y H:i:s')
    ]);

    $reminders = [];

    $reminderIntervals = [
        ['type' => '24h', 'hours_before' => 24],
        ['type' => '3h', 'hours_before' => 3],
        ['type' => '30min', 'hours_before' => 0.5],
    ];

    foreach ($reminderIntervals as $interval) {
        $sendAt = $meetingDateTime->copy()->subHours($interval['hours_before']);

        if ($sendAt->isFuture()) {
            $reminders[] = [
                'phone' => $phone, // Usar o telefone normalizado
                'name' => $name,
                'meeting_datetime' => $meetingDateTime->toISOString(),
                'type' => $interval['type'],
                'send_at' => $sendAt->toISOString(),
                'send_at_local' => $sendAt->format('d/m/Y H:i:s'),
                'created_at' => now()->toISOString()
            ];
        }
    }

    $path = storage_path('app/reminders.json');

    if (!file_exists($path)) {
        file_put_contents($path, json_encode([]));
    }

    $allReminders = json_decode(file_get_contents($path), true) ?? [];
    $allReminders = array_merge($allReminders, $reminders);

    file_put_contents($path, json_encode($allReminders, JSON_PRETTY_PRINT));

    Log::info('Lembretes salvos no arquivo', [
        'phone' => $phone,
        'reminders_count' => count($reminders),
        'total_reminders_in_file' => count($allReminders)
    ]);

    return count($reminders);
}

function getPendingReminders()
{
    $path = storage_path('app/reminders.json');
    if (!file_exists($path))
        return [];
    return json_decode(file_get_contents($path), true) ?? [];
}

function removeReminder($index)
{
    $path = storage_path('app/reminders.json');
    $reminders = json_decode(file_get_contents($path), true) ?? [];
    
    Log::info('🗑️ Removendo lembrete', [
        'index' => $index,
        'total_before' => count($reminders),
        'reminder_to_remove' => $reminders[$index] ?? null
    ]);
    
    array_splice($reminders, $index, 1);
    file_put_contents($path, json_encode($reminders, JSON_PRETTY_PRINT));
    
    Log::info('✅ Lembrete removido', [
        'total_after' => count($reminders)
    ]);
}

function getReminderMessage($type, $name, Carbon $meetingDateTime, $phone = null)
{
    $date = $meetingDateTime->format('d/m/Y');
    $time = $meetingDateTime->format('H:i');

    // Busca o link do Meet do agendamento se tiver o phone
    $meetLink = null;
    if ($phone) {
        $meetingData = getMeetingSchedule($phone);
        $meetLink = $meetingData['meet_link'] ?? null;
        
        Log::info('🔗 Buscando link do Meet', [
            'phone' => $phone,
            'meet_link_found' => !empty($meetLink),
            'meeting_data' => $meetingData
        ]);
    }

    $messages = [
        '24h' => "Oi {$name}! 👋

Lembrete: sua reunião está marcada para amanhã!

📅 {$date}
🕐 {$time}

Nos vemos lá! 😊",

        '3h' => "Olá {$name}! ⏰

Sua reunião acontece daqui a 3 horas:

📅 Hoje às {$time}

Já está preparado(a)? Qualquer dúvida, me avise!",

        '1h' => "⏳ {$name}, faltam 1 hora para sua reunião!

⏰ Horário: {$time}

Quase lá! Te vejo em breve! 😊",

        '30min' => "🔔 {$name}, sua reunião é daqui a 30 minutos!

⏰ Horário: {$time}

Te aguardo! Até já! 🚀",

        'start' => "🎉 Hora da reunião, {$name}!

🕐 Agora: {$time}

🔗 Link da reunião: " . ($meetLink ? $meetLink : "Link será enviado em instantes") . "

Te espero lá! 😊"
    ];

    $message = $messages[$type] ?? "Lembrete: sua reunião é em {$date} às {$time}";
    
    Log::info('📝 Mensagem de lembrete gerada', [
        'type' => $type,
        'phone' => $phone,
        'meet_link_included' => !empty($meetLink),
        'message_length' => strlen($message)
    ]);
    
    return $message;
}


// funções para followup

function scheduleFollowUps($phone, $name)
{
    $phone = normalizePhone($phone);

    Log::info('Iniciando agendamento de follow-ups', ['phone' => $phone, 'name' => $name]);

    $followups = [];

    $followupIntervals = [
        ['type' => '2h', 'hours_after' => 2],
        ['type' => '24h', 'hours_after' => 24],
        ['type' => '48h', 'hours_after' => 48],
    ];

    $calendarLink = 'https://app.simplymeet.me/profplay/profplay-60-1761248599757';

    foreach ($followupIntervals as $interval) {
        $sendAt = now()->addHours($interval['hours_after']);

        $followups[] = [
            'phone' => $phone,
            'name' => $name,
            'type' => $interval['type'],
            'calendar_link' => $calendarLink,
            'send_at' => $sendAt->toISOString(),
            'send_at_local' => $sendAt->format('d/m/Y H:i:s'),
            'created_at' => now()->toISOString()
        ];
    }

    Log::info('Follow-ups preparados', ['count' => count($followups), 'followups' => $followups]);

    $path = storage_path('app/followups.json');
    Log::info('Caminho do arquivo', ['path' => $path]);

    if (!file_exists($path)) {
        Log::info('Arquivo não existe, criando novo');
        file_put_contents($path, json_encode([]));
    }

    $allFollowups = json_decode(file_get_contents($path), true) ?? [];
    Log::info('Follow-ups existentes', ['count' => count($allFollowups)]);

    $allFollowups = array_merge($allFollowups, $followups);
    Log::info('Total de follow-ups após merge', ['count' => count($allFollowups)]);

    $saved = file_put_contents($path, json_encode($allFollowups, JSON_PRETTY_PRINT));
    Log::info('Arquivo salvo', ['bytes' => $saved, 'path' => $path]);

    Log::info('Follow-ups agendados', [
        'count' => count($followups),
        'phone' => $phone,
        'intervals' => array_map(function ($f) {
            return ['type' => $f['type'], 'send_at_local' => $f['send_at_local']];
        }, $followups)
    ]);
}

function getPendingFollowUps()
{
    $path = storage_path('app/followups.json');
    if (!file_exists($path))
        return [];
    return json_decode(file_get_contents($path), true) ?? [];
}

function removeFollowUp($index)
{
    $path = storage_path('app/followups.json');
    $followups = json_decode(file_get_contents($path), true) ?? [];
    array_splice($followups, $index, 1);
    file_put_contents($path, json_encode($followups, JSON_PRETTY_PRINT));
}

function cancelFollowUps($phone)
{
    $phone = normalizePhone($phone);

    Log::info('Iniciando cancelamento de follow-ups', ['phone' => $phone]);

    $path = storage_path('app/followups.json');
    if (!file_exists($path)) {
        Log::info('Nenhum arquivo de follow-ups encontrado', ['phone' => $phone]);
        return;
    }

    $followups = json_decode(file_get_contents($path), true) ?? [];
    $initialCount = count($followups);

    Log::info('Follow-ups antes do cancelamento', [
        'total' => $initialCount,
        'followups' => $followups
    ]);

    $remainingFollowups = [];
    $canceledFollowups = [];

    foreach ($followups as $followup) {
        // Normaliza o telefone do follow-up para comparar
        $followupPhone = normalizePhone($followup['phone']);

        if ($followupPhone === $phone) {
            $canceledFollowups[] = $followup;
            Log::info('Cancelando follow-up', ['followup' => $followup]);
        } else {
            $remainingFollowups[] = $followup;
        }
    }

    $finalCount = count($remainingFollowups);
    $canceledCount = count($canceledFollowups);

    Log::info('Resultado do cancelamento', [
        'phone_buscado' => $phone,
        'inicial' => $initialCount,
        'cancelados' => $canceledCount,
        'restantes' => $finalCount,
        'followups_cancelados' => $canceledFollowups
    ]);

    file_put_contents($path, json_encode($remainingFollowups, JSON_PRETTY_PRINT));

    Log::info('Follow-ups cancelados', [
        'phone' => $phone,
        'canceled_count' => $canceledCount,
        'remaining_count' => $finalCount
    ]);
}

function cancelFollowUpsByName($name)
{
    $normalizedName = mb_strtolower(trim($name));

    Log::info('Iniciando cancelamento de follow-ups por nome', ['name' => $name, 'normalized' => $normalizedName]);

    $path = storage_path('app/followups.json');
    if (!file_exists($path)) {
        Log::info('Nenhum arquivo de follow-ups encontrado');
        return;
    }

    $followups = json_decode(file_get_contents($path), true) ?? [];
    $initialCount = count($followups);

    $remainingFollowups = [];
    $canceledFollowups = [];

    foreach ($followups as $followup) {
        $followupName = mb_strtolower(trim($followup['name']));

        // Compara nome exato ou parcial
        if (
            $followupName === $normalizedName ||
            strpos($followupName, $normalizedName) !== false ||
            strpos($normalizedName, $followupName) !== false
        ) {
            $canceledFollowups[] = $followup;
            Log::info('Cancelando follow-up por nome', [
                'followup_name' => $followup['name'],
                'searched_name' => $name
            ]);
        } else {
            $remainingFollowups[] = $followup;
        }
    }

    $finalCount = count($remainingFollowups);
    $canceledCount = count($canceledFollowups);

    Log::info('Resultado do cancelamento por nome', [
        'name_buscado' => $name,
        'inicial' => $initialCount,
        'cancelados' => $canceledCount,
        'restantes' => $finalCount
    ]);

    file_put_contents($path, json_encode($remainingFollowups, JSON_PRETTY_PRINT));

    Log::info('Follow-ups cancelados por nome', [
        'name' => $name,
        'canceled_count' => $canceledCount,
        'remaining_count' => $finalCount
    ]);
}

function hasScheduledMeeting($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/meeting_schedules.json');
    if (!file_exists($path))
        return false;

    $schedules = json_decode(file_get_contents($path), true) ?? [];
    return isset($schedules[$phone]);
}

function getFollowUpMessage($type, $name)
{
    $calendarLink = 'https://app.simplymeet.me/profplay/profplay-60-1761248599757';

    $messages = [
        '2h' => "Oi {$name}! 👋

Vi que você ainda não agendou sua reunião. Tudo bem por aí?

Caso tenha alguma dúvida sobre o agendamento, estou aqui para ajudar! 😊

Link: {$calendarLink}",

        '24h' => "Olá {$name}! 

Só passando para lembrar que você ainda pode agendar sua reunião quando for melhor para você.

O link continua disponível: {$calendarLink}

Qualquer coisa, me avise! 📅",

        '48h' => "{$name}, notei que você demonstrou interesse mas ainda não agendou.

Há algo em que eu possa ajudar? Alguma dúvida sobre o processo?

Estou à disposição! O link é este: {$calendarLink}

Se preferir, podemos conversar sobre suas necessidades primeiro. O que acha? 😊"
    ];

    return $messages[$type] ?? "Olá {$name}! Ainda está interessado em agendar? {$calendarLink}";
}
function markWaitingReminderPermission($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/reminder_permissions.json');

    if (!file_exists($path)) {
        file_put_contents($path, json_encode([]));
    }

    $permissions = json_decode(file_get_contents($path), true);
    $permissions[$phone] = [
        'status' => 'waiting',
        'created_at' => now()->toISOString()
    ];

    file_put_contents($path, json_encode($permissions, JSON_PRETTY_PRINT));
    Log::info('Marcado como aguardando permissão', ['phone' => $phone]);
}

function checkIfWaitingReminderPermission($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/reminder_permissions.json');

    if (!file_exists($path))
        return false;

    $permissions = json_decode(file_get_contents($path), true);
    return isset($permissions[$phone]) && $permissions[$phone]['status'] === 'waiting';
}

function saveReminderPermission($phone, $granted)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/reminder_permissions.json');

    if (!file_exists($path)) {
        file_put_contents($path, json_encode([]));
    }

    $permissions = json_decode(file_get_contents($path), true);
    $permissions[$phone] = [
        'status' => $granted ? 'granted' : 'denied',
        'updated_at' => now()->toISOString()
    ];

    file_put_contents($path, json_encode($permissions, JSON_PRETTY_PRINT));
    Log::info('Permissão salva', ['phone' => $phone, 'granted' => $granted]);
}

function hasReminderPermission($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/reminder_permissions.json');

    if (!file_exists($path))
        return false;

    $permissions = json_decode(file_get_contents($path), true);
    return isset($permissions[$phone]) && $permissions[$phone]['status'] === 'granted';
}

function getMeetingSchedule($phone)
{
    $phone = normalizePhone($phone);
    $path = storage_path('app/meeting_schedules.json');
    
    if (!file_exists($path)) {
        Log::warning('Arquivo meeting_schedules.json não encontrado', ['phone' => $phone]);
        return null;
    }
    
    $schedules = json_decode(file_get_contents($path), true);
    $schedule = $schedules[$phone] ?? null;
    
    Log::info('📋 Buscando agendamento', [
        'phone' => $phone,
        'schedule_found' => !empty($schedule),
        'has_meet_link' => isset($schedule['meet_link'])
    ]);
    
    return $schedule;
}