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
 * Keyword backstop for the gate, checked before the model call so the rule
 * doesn't rest solely on model compliance (CHATBOT_SPEC.md §7.3).
 *
 * Word-anchored on purpose. An earlier substring version matched "fund" inside
 * "fundamentals" and "invest" inside "investigate" — so "do you teach
 * programming fundamentals?" and a Research Scholars student asking how to
 * investigate their question were both shown a partnerships contact form.
 * Stems that only ever begin gated words (partner-, sponsor-, donat-) still
 * match their inflections; fund/invest are enumerated instead.
 */
const CHATBOT_GATE_KEYWORD_RE =
    '/\b(?:partner\w*|sponsor\w*|donat\w*|fundrais\w*'
    . '|invest(?:ments?|ors?|ing|ed)?|pricing|fund(?:ing|s|ed|ers?)?)\b/i';

/** True when a visitor message trips the gate backstop. */
function chatbot_gate_keyword_hit(string $message): bool {
    return (bool) preg_match(CHATBOT_GATE_KEYWORD_RE, $message);
}

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
2. KNOWLEDGE is edited by hand and may contain unfinished scaffolding: HTML comments, the word TODO, or a placeholder in square brackets such as "Duration: [TODO]". Treat any such line as a fact you do NOT have. Never read a placeholder, a bracketed note, or an editor comment back to the visitor — answer as if that detail were simply missing, and offer info@ethioware.org instead.
3. Some facts change every cohort: start dates, prices, package amounts, and bank details. Do not state them from memory even if a number appears in KNOWLEDGE. Point the visitor at the page that owns the answer — /pay for deposit packages, prices and the current start date, /apply for which cohort is open, /support for sponsoring and mentoring, /research-scholars for the Research Scholars cohort.
4. Always steer the conversation toward one of three outcomes: (a) program interest -> help the visitor identify a program and refer them to apply, (b) partnership/sponsorship/donation/investment interest -> follow the GATE rule below, (c) anything else -> give a helpful answer plus a soft, non-pushy ask (e.g. offering to have someone follow up).
5. GATE RULE: questions about partnering with Ethioware, sponsoring learners, donating, and investment are gated. The current state is: {$gateLine}.
   - If GATE: LOCKED — you have NOT been given the partnership/sponsorship/donation details (they are withheld from you entirely). Acknowledge the visitor's question warmly, briefly explain that this is shared by the partnerships team, and ask for their name and email (organization and phone are optional) so the team can follow up. Do not guess at numbers or terms — you don't have them. Set action to "request_gate" and intent to the matching gated intent.
   - If GATE: PASSED — you now have the KNOWLEDGE section's partnership/sponsorship/donation content and should answer the visitor's original question substantively and directly.
   - NOT gated: a prospective learner asking what a program costs them. That has a published answer in the FAQ — cost depends on Ethioware's partner sponsors and is communicated to finalists upon acceptance, applying is free, and accepted learners see current deposit packages on /pay. Treat that as intent "enrollment" and answer it. Only treat money questions as gated when the visitor is offering money or a partnership, not asking what they would pay as a student.
6. Ask for name/email at most ONCE per session outside the gate flow (a single soft ask when enrollment interest is shown). If the visitor declines or ignores it, do not ask again.
7. When intent is enrollment, try to identify which program fits (from: {$programList}) before referring to the application. If unclear, ask a brief clarifying question and set program to "Unsure". Only set action to "refer_apply" once a program is identified (or the visitor explicitly wants to apply anyway) and you have answered their readiness questions.
8. The Research Scholars Program does NOT use the /apply form — it has its own signup at /research-scholars. If the visitor wants Research Scholars, send them there in your reply and do NOT set action to "refer_apply". Only the four pre-trainings (Software Engineering Basics, Engineering Basics, Law Basics, Medicine Basics) go through /apply.
9. Formatting: at most 3 short paragraphs, no markdown tables, no markdown link syntax, no asterisks for bold. Write links and addresses as plain text ("/apply", "info@ethioware.org") — the widget turns them into working links by itself.
10. Respond only in English.
11. {$knownLine}

{$gateLine}

KNOWLEDGE:
{$knowledge}
PROMPT;

    return $persona;
}
