<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Conversations\ConversationServiceInterface;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\CodConversation;
use App\Models\CodWhatsAppMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The WhatsApp conversations inbox inside the COD System.
 *
 * List: every tracked conversation (product inquiries and order
 * replies), filterable by state, with counts per state. One
 * conversation: the full message thread (cod_whatsapp_messages for
 * the phone, oldest first, AI / customer / manual / system messages
 * told apart), the linked order and product, the collected
 * information, a manual reply form and the handoff button.
 *
 * A manual reply is sent through the configured WhatsApp provider
 * and recorded like any other message, so the customer's answer
 * comes back to this same page. The handoff button stops the AI for
 * that conversation (the agent never replies again) and records the
 * handoff.
 *
 * Guarded by the `cod.conversations` ACL key.
 */
class CodConversationController extends Controller
{
    public function __construct(
        protected ConversationServiceInterface $conversations,
        protected WhatsAppServiceInterface $whatsapp,
    ) {}

    /**
     * Conversations, newest activity first, optionally filtered by state.
     */
    public function index(Request $request): View
    {
        abort_unless(bouncer()->hasPermission('cod.conversations'), 403);

        $state = $request->query('state');

        if (! in_array($state, CodConversation::STATES, true)) {
            $state = null;
        }

        $query = CodConversation::query()->with('codOrder')->orderByDesc('last_message_at')->orderByDesc('id');

        $counts = [
            'all' => (clone $query)->count(),
            CodConversation::STATE_COLLECTING_INFO => (clone $query)->where('state', CodConversation::STATE_COLLECTING_INFO)->count(),
            CodConversation::STATE_AWAITING_CONFIRMATION => (clone $query)->where('state', CodConversation::STATE_AWAITING_CONFIRMATION)->count(),
            CodConversation::STATE_ESCALATED => (clone $query)->where('state', CodConversation::STATE_ESCALATED)->count(),
        ];

        if ($state) {
            $query->where('state', $state);
        }

        return view('admin::cod.conversations')->with([
            'conversations' => $query->paginate(20)->withQueryString(),
            'states' => CodConversation::STATES,
            'stateCounts' => $counts,
            'state' => $state,
            'dashboardUrl' => route('admin.cod.index'),
        ]);
    }

    /**
     * One conversation: the full thread, the linked order and
     * product, the collected information, the reply form.
     */
    public function show(CodConversation $conversation): View
    {
        abort_unless(bouncer()->hasPermission('cod.conversations'), 403);

        $conversation->load('codOrder');

        return view('admin::cod.conversation')->with([
            'conversation' => $conversation,
            'messages' => $conversation->messages()->get(),
            'indexUrl' => route('admin.cod.conversations.index'),
        ]);
    }

    /**
     * Send a manual reply from the inbox. The message goes through
     * the configured WhatsApp provider and is recorded with sender
     * 'human', so the thread on this page stays complete. The
     * customer's answer comes back to this same page.
     */
    public function reply(Request $request, CodConversation $conversation): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.conversations'), 403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $result = $this->whatsapp->sendConversationMessage(
            $conversation,
            $data['message'],
            CodWhatsAppMessage::SENDER_HUMAN,
        );

        $this->conversations->touch($conversation);

        if (! $result['success']) {
            return back()->with('error', trans('admin::app.cod.conversations.message-failed', ['error' => $result['message']]));
        }

        return back()->with('success', trans('admin::app.cod.conversations.message-sent'));
    }

    /**
     * Hand the conversation to a human: the AI stops replying to
     * this customer. The team answers from this page from now on.
     */
    public function handoff(CodConversation $conversation): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.conversations'), 403);

        $this->conversations->escalate($conversation, 'Handed to a human from the conversations inbox.');

        return back()->with('success', trans('admin::app.cod.conversations.handed-to-human'));
    }
}
