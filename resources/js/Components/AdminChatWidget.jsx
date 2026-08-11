import React, { useState, useRef, useEffect } from 'react';
import axios from 'axios';

export default function AdminChatWidget() {
    const [isOpen, setIsOpen] = useState(false);
    const [messages, setMessages] = useState([
        {
            role: 'assistant',
            content: 'Hola 👋 Soy Ualdo Admin, tu co-piloto de gestión. ¿En qué te puedo ayudar hoy? (ej: "¿Qué productos me faltan?", "Revisa la agenda de hoy", "Actualiza stock de amalgama")',
        },
    ]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [pendingAction, setPendingAction] = useState(null);
    const messagesEndRef = useRef(null);

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(() => {
        if (isOpen) {
            scrollToBottom();
        }
    }, [messages, isOpen]);

    const handleSend = async (e) => {
        e.preventDefault();
        if (!input.trim() || loading) return;

        const userText = input.trim();
        setInput('');
        setMessages((prev) => [...prev, { role: 'user', content: userText }]);
        setLoading(true);

        try {
            const res = await axios.post('/admin/chat', { message: userText });
            const reply = res.data.reply;

            // Check if response indicates approval requirement
            try {
                const parsed = JSON.parse(reply);
                if (parsed && parsed.status === 'approval_required') {
                    setPendingAction(parsed);
                    setMessages((prev) => [
                        ...prev,
                        {
                            role: 'assistant',
                            content: `⚠️ Accion requerida: ${parsed.message}`,
                            token: parsed.token,
                            details: parsed.details,
                        },
                    ]);
                } else {
                    setMessages((prev) => [...prev, { role: 'assistant', content: reply }]);
                }
            } catch {
                setMessages((prev) => [...prev, { role: 'assistant', content: reply }]);
            }
        } catch (error) {
            setMessages((prev) => [
                ...prev,
                { role: 'assistant', content: '❌ Ocurrió un error al procesar tu mensaje.' },
            ]);
        } finally {
            setLoading(false);
        }
    };

    const handleApprove = async (token) => {
        setLoading(true);
        try {
            const res = await axios.post('/admin/chat/approve', { token });
            setPendingAction(null);
            setMessages((prev) => [
                ...prev,
                { role: 'assistant', content: `✅ ${res.data.message}\nResultado: ${JSON.stringify(res.data.result)}` },
            ]);
        } catch (error) {
            setMessages((prev) => [
                ...prev,
                { role: 'assistant', content: `❌ ${error.response?.data?.message || 'Error al aprobar la acción.'}` },
            ]);
        } finally {
            setLoading(false);
        }
    };

    const handleCancel = async (token) => {
        setLoading(true);
        try {
            const res = await axios.post('/admin/chat/cancel', { token });
            setPendingAction(null);
            setMessages((prev) => [
                ...prev,
                { role: 'assistant', content: `🚫 ${res.data.message}` },
            ]);
        } catch (error) {
            setMessages((prev) => [
                ...prev,
                { role: 'assistant', content: `❌ ${error.response?.data?.message || 'Error al cancelar la acción.'}` },
            ]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed bottom-6 right-6 z-50 font-sans">
            {/* Toggle Button */}
            {!isOpen && (
                <button
                    onClick={() => setIsOpen(true)}
                    className="flex items-center space-x-3 bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal hover:from-ualdo-teal hover:to-ualdo-profundo text-white font-extrabold py-3.5 px-6 rounded-full shadow-2xl transition-all duration-300 transform hover:scale-105 border border-white/20"
                >
                    <span className="text-xl">🤖</span>
                    <span>Ualdo Admin Chat</span>
                </button>
            )}

            {/* Chat Drawer Widget */}
            {isOpen && (
                <div className="w-96 h-[520px] bg-slate-900 border border-ualdo-teal/40 rounded-3xl shadow-2xl flex flex-col overflow-hidden backdrop-blur-xl">
                    {/* Widget Header */}
                    <div className="bg-gradient-to-r from-ualdo-profundo via-slate-800 to-ualdo-tinta px-5 py-4 flex items-center justify-between border-b border-ualdo-teal/30">
                        <div className="flex items-center space-x-3">
                            <div className="w-8 h-8 rounded-full bg-ualdo-turquesa/20 border border-ualdo-turquesa flex items-center justify-center text-lg">
                                🤖
                            </div>
                            <div>
                                <h4 className="text-white font-bold text-sm">Ualdo Co-Piloto AI</h4>
                                <span className="text-emerald-400 text-xs flex items-center gap-1">
                                    <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                    En línea (Seguro & Multitenant)
                                </span>
                            </div>
                        </div>
                        <button
                            onClick={() => setIsOpen(false)}
                            className="text-gray-400 hover:text-white text-xl font-bold p-1 rounded-lg hover:bg-white/10 transition-all"
                        >
                            ✕
                        </button>
                    </div>

                    {/* Chat Messages Body */}
                    <div className="flex-1 p-4 overflow-y-auto space-y-3.5 text-xs">
                        {messages.map((msg, idx) => (
                            <div
                                key={idx}
                                className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                            >
                                <div
                                    className={`max-w-[85%] p-3 rounded-2xl ${
                                        msg.role === 'user'
                                            ? 'bg-ualdo-teal text-white rounded-br-none border border-ualdo-turquesa/30'
                                            : 'bg-slate-800/90 text-gray-200 rounded-bl-none border border-slate-700'
                                    }`}
                                >
                                    <p className="whitespace-pre-wrap leading-relaxed">{msg.content}</p>
                                    
                                    {/* Confirmation Buttons for Pending Actions */}
                                    {msg.token && (
                                        <div className="mt-3 pt-2 border-t border-white/10 flex space-x-2">
                                            <button
                                                onClick={() => handleApprove(msg.token)}
                                                disabled={loading}
                                                className="bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-3 py-1.5 rounded-xl transition-all shadow"
                                            >
                                                ✅ Aprobar
                                            </button>
                                            <button
                                                onClick={() => handleCancel(msg.token)}
                                                disabled={loading}
                                                className="bg-rose-600 hover:bg-rose-500 text-white font-bold px-3 py-1.5 rounded-xl transition-all shadow"
                                            >
                                                🚫 Cancelar
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        ))}
                        {loading && (
                            <div className="flex justify-start">
                                <div className="bg-slate-800 p-3 rounded-2xl text-gray-400 flex items-center space-x-2">
                                    <span className="animate-spin">⏳</span>
                                    <span>Ualdo está procesando...</span>
                                </div>
                            </div>
                        )}
                        <div ref={messagesEndRef} />
                    </div>

                    {/* Chat Input Form */}
                    <form onSubmit={handleSend} className="p-3 bg-slate-950 border-t border-ualdo-teal/20 flex items-center space-x-2">
                        <input
                            type="text"
                            value={input}
                            onChange={(e) => setInput(e.target.value)}
                            placeholder="Pregunta o instruye a Ualdo..."
                            className="flex-1 bg-slate-900 text-white border border-slate-700 rounded-xl px-3.5 py-2.5 text-xs focus:outline-none focus:border-ualdo-turquesa placeholder-gray-500"
                        />
                        <button
                            type="submit"
                            disabled={loading || !input.trim()}
                            className="bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal hover:opacity-90 text-white font-bold px-4 py-2.5 rounded-xl transition-all disabled:opacity-50 text-xs"
                        >
                            Enviar
                        </button>
                    </form>
                </div>
            )}
        </div>
    );
}
