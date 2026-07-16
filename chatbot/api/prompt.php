<?php
/**
 * System-prompt builder + knowledge loader.
 *
 * The hard partnership gate lives here: chatbot_load_knowledge() only reads
 * 40-partnerships.md off disk when $gatePassed is true, so an ungated model
 * call physically never receives that content. This is what makes the gate
 * structural rather than a prompt instruction the model could ignore.
 * See CHATBOT_SPEC.md §5 and §7.3.
 */

const CHATBOT_GATED_KNOWLEDGE_FILE = '40-partnerships.md';
const CHATBOT_KNOWLEDGE_TOKEN_BUDGET = 12000; // ~48,000 chars, see CHATBOT_SPEC.md §5
const CHATBOT_KNOWLEDGE_CHAR_BUDGET = CHATBOT_KNOWLEDGE_TOKEN_BUDGET * 4;

const CHATBOT_PROGRAMS = [
    'Software Engineering Basics',
    'Engineering Basics',
    'Law Basics',
    'Medicine Basics',
    'Research Scholars Program',
    'Unsure',
];

const CHATBOT_INTENTS = [
    'general', 'enrollment', 'partnership', 'pricing', 'donation',
    'investment', 'support', 'other',
];

// Intents that must never be answered substantively until the gate is passed.
const CHATBOT_GATED_INTENTS = ['partnership', 'pricing', 'donation', 'investment'];

/**
 * Read chatbot/knowledge/*.md in filename order, concatenated. Excludes the
 * gated file unless $gatePassed. Truncates at CHATBOT_KNOWLEDGE_CHAR_BUDGET
 * with a logged warning rather than silently degrading quality.
 *
 * @return array{0: string, 1: bool} [$text, $truncated]
 */
function chatbot_load_knowledge(bool $gatePassed): array {
    $dir = __DIR__ . '/../knowledge';
    $files = glob($dir . '/*.md') ?: [];
    sort($files, SORT_STRING); // numeric filename prefixes control order

    $chunks = [];
    foreach ($files as $file) {
        $basename = basename($file);
        if ($basename === CHATBOT_GATED_KNOWLEDGE_FILE && !$gatePassed) {
            continue;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            continue;
        }
        $chunks[] = $content;
    }

    $text = implode("\n\n---\n\n", $chunks);
    $truncated = false;
    if (strlen($text) > CHATBOT_KNOWLEDGE_CHAR_BUDGET) {
        $text = substr($text, 0, CHATBOT_KNOWLEDGE_CHAR_BUDGET);
        $truncated = true;
        error_log(sprintf(
            'chatbot: knowledge base truncated at %d chars (budget %d)',
            strlen($text),
            CHATBOT_KNOWLEDGE_CHAR_BUDGET
        ));
    }

    return [$text, $truncated];
}

/**
 * Build the full system_instruction text sent to Gemini for this turn.
 *
 * @param array $lead Row from chatbot_leads (or a fresh default array).
 */
function chatbot_build_system_prompt(array $lead): string {
    $gatePassed = ($lead['gate_state'] ?? 'none') === 'passed';
    [$knowledge, ] = chatbot_load_knowledge($gatePassed);

    $gateLine = $gatePassed ? 'GATE: PASSED' : 'GATE: LOCKED';

    $known = [];
    if (!empty($lead['name'])) $known[] = 'name=' . $lead['name'];
    if (!empty($lead['email'])) $known[] = 'email=' . $lead['email'];
    if (!empty($lead['phone'])) $known[] = 'phone=' . $lead['phone'];
    if (!empty($lead['organization'])) $known[] = 'organization=' . $lead['organization'];
    if (!empty($lead['program_interest'])) $known[] = 'program=' . $lead['program_interest'];
    $knownLine = $known ? ('Known visitor info: ' . implode(', ', $known) . '. Never re-ask for these.')
                         : 'Known visitor info: none yet.';

    $programList = implode(', ', array_slice(CHATBOT_PROGRAMS, 0, -1)); // exclude "Unsure" from the prose list

    $persona = <<<PROMPT
You are the Ethioware website chat assistant — a warm, concise guide to Ethioware's EdTech programs. Ethioware is a non-profit connecting high-school graduates with industry mentors.

RULES (follow all of these):
1. Answer only from the KNOWLEDGE section below. If the answer isn't in the knowledge, say so plainly and offer info@ethioware.org — never invent program details, prices, dates, or facts.
2. Always steer the conversation toward one of three outcomes: (a) program interest -> help the visitor identify a program and refer them to apply, (b) partnership/pricing/donation/investment interest -> follow the GATE rule below, (c) anything else -> give a helpful answer plus a soft, non-pushy ask (e.g. offering to have someone follow up).
3. GATE RULE: partnership, pricing, donation, and investment questions are gated. The current state is: {$gateLine}.
   - If GATE: LOCKED — you have NOT been given partnership/pricing/donation details (they are withheld from you entirely). Acknowledge the visitor's question warmly, briefly explain that this is shared by the partnerships team, and ask for their name and email (organization and phone are optional) so the team can follow up. Do not guess at numbers or terms — you don't have them. Set action to "request_gate".
   - If GATE: PASSED — you now have the KNOWLEDGE section's partnership/pricing/donation content and should answer the visitor's original question substantively and directly.
4. Ask for name/email at most ONCE per session outside the gate flow (a single soft ask when enrollment interest is shown). If the visitor declines or ignores it, do not ask again.
5. When intent is enrollment, try to identify which program fits (from: {$programList}) before referring to the application. If unclear, ask a brief clarifying question and set program to "Unsure". Only set action to "refer_apply" once a program is identified (or the visitor explicitly wants to apply anyway) and you have answered their readiness questions.
6. Formatting: at most 3 short paragraphs, no markdown tables, plain text links (e.g. "/apply", "info@ethioware.org") — no markdown link syntax.
7. Respond only in English.
8. {$knownLine}

{$gateLine}

KNOWLEDGE:
{$knowledge}
PROMPT;

    return $persona;
}
