<?php
/**
 * Trợ Lý AI Chatbot Widget (UI Component)
 * Hiển thị nút tròn nổi và khung chat AI cho toàn bộ hệ thống
 */
?>
<!-- AI Chatbot Floating Trigger Button -->
<div id="ai-chatbot-widget-root">
    <!-- Floating Round Button -->
    <button id="ai-chatbot-toggle-btn" class="ai-chatbot-btn-floating" aria-label="Mở Trợ lý AI" onclick="toggleAiChatbot()">
        <span class="ai-chatbot-badge-pulse"></span>
        <div class="ai-chatbot-btn-icon-open">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2C6.477 2 2 6.477 2 12C2 13.82 2.487 15.527 3.338 17L2.5 21.5L7 20.662C8.473 21.513 10.18 22 12 22C17.523 22 22 17.523 22 12C22 6.477 17.523 2 12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M8 12H8.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M12 12H12.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M16 12H16.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="ai-chatbot-btn-icon-close" style="display: none;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </div>
        <span class="ai-chatbot-tooltip">Trợ lý AI ✨</span>
    </button>

    <!-- Chatbot Window Popup -->
    <div id="ai-chatbot-window" class="ai-chatbot-card">
        <!-- Header -->
        <div class="ai-chatbot-header">
            <div class="ai-chatbot-header-info">
                <div class="ai-chatbot-avatar">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2 2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                        <rect x="4" y="8" width="16" height="12" rx="4"/>
                        <circle cx="9" cy="13" r="1.5" fill="currentColor"/>
                        <circle cx="15" cy="13" r="1.5" fill="currentColor"/>
                        <path d="M10 17h4"/>
                    </svg>
                    <span class="ai-chatbot-status-dot"></span>
                </div>
                <div>
                    <h3 class="ai-chatbot-title">
                        Trợ Lý AI <span class="ai-chatbot-tag">PRO</span>
                    </h3>
                    <p class="ai-chatbot-subtitle">Hỗ trợ tự động 24/7 • Chợ Đồ Cũ</p>
                </div>
            </div>
            <div class="ai-chatbot-header-actions">
                <button type="button" class="ai-chatbot-action-btn" title="Xóa lịch sử trò chuyện" onclick="clearAiChatbotHistory()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                </button>
                <button type="button" class="ai-chatbot-action-btn" title="Đóng khung chat" onclick="toggleAiChatbot()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Messages Body -->
        <div id="ai-chatbot-messages" class="ai-chatbot-body">
            <!-- Default Bot Greeting -->
            <div class="ai-msg-row ai-msg-bot">
                <div class="ai-msg-avatar">🤖</div>
                <div class="ai-msg-content">
                    <div class="ai-msg-bubble">
                        Xin chào! 👋 Tôi là <strong>Trợ lý AI của Chợ Đồ Cũ</strong>.<br>
                        Tôi có thể hỗ trợ bạn tìm kiếm sản phẩm, giải đáp thắc mắc về đăng tin, kiểm tra đơn hàng hoặc quy trình mua bán.
                    </div>
                    
                    <!-- Quick Suggestion Chips -->
                    <div class="ai-chatbot-suggestions">
                        <span class="ai-chip-title">💡 Gợi ý câu hỏi phổ biến:</span>
                        <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Hướng dẫn cách đăng bán sản phẩm?')">🛍️ Đăng bán sản phẩm như thế nào?</button>
                        <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Làm thế nào để kiểm tra đơn hàng đã mua?')">📦 Kiểm tra đơn hàng của tôi</button>
                        <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Phương thức thanh toán và nạp tiền vào ví?')">💳 Nạp tiền & Thanh toán thế nào?</button>
                        <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Chính sách bảo mật và an toàn mua bán?')">🛡️ Chính sách mua bán an toàn</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer / Input Form -->
        <div class="ai-chatbot-footer">
            <form id="ai-chatbot-input-form" onsubmit="handleAiChatSubmit(event)">
                <div class="ai-chatbot-input-wrapper">
                    <input type="text" id="ai-chatbot-input" placeholder="Nhập câu hỏi của bạn..." autocomplete="off" />
                    <button type="submit" id="ai-chatbot-send-btn" aria-label="Gửi tin nhắn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </div>
            </form>
            <div class="ai-chatbot-copyright">
                ⚡ Đang ở chế độ Xem trước Giao diện (UI Preview Mode)
            </div>
        </div>
    </div>
</div>

<!-- Styles for AI Chatbot Widget -->
<style>
/* Root container reset */
#ai-chatbot-widget-root {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #1e293b;
    position: fixed;
    bottom: 0;
    right: 0;
    z-index: 999999;
}

/* Floating Trigger Button */
.ai-chatbot-btn-floating {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563eb 0%, #4f46e5 50%, #7c3aed 100%);
    color: #ffffff;
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 999999;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    outline: none;
    animation: floatBot 3s ease-in-out infinite;
}

.ai-chatbot-btn-floating:hover {
    transform: scale(1.1) translateY(-2px);
    box-shadow: 0 14px 30px rgba(37, 99, 235, 0.6), 0 0 0 4px rgba(99, 102, 241, 0.2);
}

.ai-chatbot-btn-floating:active {
    transform: scale(0.95);
}

@keyframes floatBot {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
}

/* Pulsing Badge Dot */
.ai-chatbot-badge-pulse {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 14px;
    height: 14px;
    background-color: #10b981;
    border: 2px solid #ffffff;
    border-radius: 50%;
    box-shadow: 0 0 8px #10b981;
}

.ai-chatbot-badge-pulse::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background-color: #10b981;
    opacity: 0.75;
    animation: pingPulse 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
}

@keyframes pingPulse {
    75%, 100% {
        transform: scale(2.2);
        opacity: 0;
    }
}

/* Tooltip on hover */
.ai-chatbot-tooltip {
    position: absolute;
    right: 72px;
    background: #0f172a;
    color: #ffffff;
    font-size: 13px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 8px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transform: translateX(10px);
    transition: all 0.25s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.ai-chatbot-tooltip::after {
    content: '';
    position: absolute;
    top: 50%;
    right: -6px;
    transform: translateY(-50%);
    border-width: 6px 0 6px 6px;
    border-style: solid;
    border-color: transparent transparent transparent #0f172a;
}

.ai-chatbot-btn-floating:hover .ai-chatbot-tooltip {
    opacity: 1;
    transform: translateX(0);
}

/* Chatbot Main Card Window */
.ai-chatbot-card {
    position: fixed;
    bottom: 96px;
    right: 24px;
    width: 380px;
    height: 550px;
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 120px);
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(226, 232, 240, 0.8);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 999999;
    opacity: 0;
    pointer-events: none;
    transform: scale(0.92) translateY(20px);
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    transform-origin: bottom right;
}

.ai-chatbot-card.active {
    opacity: 1;
    pointer-events: auto;
    transform: scale(1) translateY(0);
}

/* Header */
.ai-chatbot-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.ai-chatbot-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ai-chatbot-avatar {
    position: relative;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
}

.ai-chatbot-status-dot {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 10px;
    height: 10px;
    background: #10b981;
    border: 2px solid #0f172a;
    border-radius: 50%;
}

.ai-chatbot-title {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
    display: flex;
    align-items: center;
    gap: 6px;

}

.ai-chatbot-tag {
    font-size: 9px;
    font-weight: 800;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #ffffff;
    padding: 2px 6px;
    border-radius: 6px;
    letter-spacing: 0.5px;
}

.ai-chatbot-subtitle {
    font-size: 11.5px;
    color: #94a3b8;
    margin: 3px 0 0 0;
}

.ai-chatbot-header-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

.ai-chatbot-action-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #cbd5e1;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.ai-chatbot-action-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

/* Body / Message Scroll Area */
.ai-chatbot-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    background: #f8fafc;
    scroll-behavior: smooth;
}

.ai-chatbot-body::-webkit-scrollbar {
    width: 5px;
}
.ai-chatbot-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

/* Messages styling */
.ai-msg-row {
    display: flex;
    gap: 10px;
    max-width: 88%;
    animation: fadeInMsg 0.25s ease-out;
}

@keyframes fadeInMsg {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

.ai-msg-bot {
    align-self: flex-start;
}

.ai-msg-user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.ai-msg-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #e0f2fe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}

.ai-msg-user .ai-msg-avatar {
    background: #e0e7ff;
}

.ai-msg-content {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ai-msg-bubble {
    padding: 10px 14px;
    border-radius: 16px;
    font-size: 13.5px;
    line-height: 1.5;
    word-break: break-word;
}

.ai-msg-bot .ai-msg-bubble {
    background: #ffffff;
    color: #1e293b;
    border-top-left-radius: 4px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}

.ai-msg-user .ai-msg-bubble {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    border-top-right-radius: 4px;
    box-shadow: 0 3px 10px rgba(37, 99, 235, 0.25);
}

/* Quick Suggestion Chips */
.ai-chatbot-suggestions {
    margin-top: 6px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ai-chip-title {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}

.ai-chip {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    font-size: 12px;
    font-weight: 500;
    padding: 8px 12px;
    border-radius: 12px;
    cursor: pointer;
    text-align: left;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.ai-chip:hover {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1d4ed8;
    transform: translateX(4px);
}

/* Typing indicator */
.ai-typing-dots {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 10px 14px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    border-top-left-radius: 4px;
    width: fit-content;
}

.ai-typing-dots span {
    width: 6px;
    height: 6px;
    background: #3b82f6;
    border-radius: 50%;
    animation: dotBlink 1.4s infinite ease-in-out both;
}

.ai-typing-dots span:nth-child(1) { animation-delay: -0.32s; }
.ai-typing-dots span:nth-child(2) { animation-delay: -0.16s; }

@keyframes dotBlink {
    0%, 80%, 100% { transform: scale(0.4); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}

/* Footer / Input */
.ai-chatbot-footer {
    padding: 12px 14px;
    background: #ffffff;
    border-top: 1px solid #f1f5f9;
}

.ai-chatbot-input-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f1f5f9;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 4px 6px 4px 14px;
    transition: all 0.2s;
}

.ai-chatbot-input-wrapper:focus-within {
    background: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

#ai-chatbot-input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 13.5px;
    color: #0f172a;
    outline: none;
    padding: 8px 0;
}

#ai-chatbot-input::placeholder {
    color: #94a3b8;
}

#ai-chatbot-send-btn {
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    border: none;
    color: #ffffff;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
}

#ai-chatbot-send-btn:hover {
    opacity: 0.9;
    transform: scale(1.05);
}

.ai-chatbot-copyright {
    font-size: 10px;
    color: #94a3b8;
    text-align: center;
    margin-top: 6px;
}
</style>

<!-- JavaScript Logic for AI Chatbot Widget UI -->
<script>
function toggleAiChatbot() {
    const windowEl = document.getElementById('ai-chatbot-window');
    const openIcon = document.querySelector('.ai-chatbot-btn-icon-open');
    const closeIcon = document.querySelector('.ai-chatbot-btn-icon-close');
    const badge = document.querySelector('.ai-chatbot-badge-pulse');

    if (!windowEl) return;

    const isActive = windowEl.classList.contains('active');

    if (isActive) {
        windowEl.classList.remove('active');
        openIcon.style.display = 'block';
        closeIcon.style.display = 'none';
        if (badge) badge.style.display = 'block';
    } else {
        windowEl.classList.add('active');
        openIcon.style.display = 'none';
        closeIcon.style.display = 'block';
        if (badge) badge.style.display = 'none';

        // Focus input field when opened
        setTimeout(() => {
            const input = document.getElementById('ai-chatbot-input');
            if (input) input.focus();
        }, 150);
    }
}

function clearAiChatbotHistory() {
    const msgBox = document.getElementById('ai-chatbot-messages');
    if (!msgBox) return;
    
    msgBox.innerHTML = `
        <div class="ai-msg-row ai-msg-bot">
            <div class="ai-msg-avatar">🤖</div>
            <div class="ai-msg-content">
                <div class="ai-msg-bubble">
                    Đã làm mới cuộc trò chuyện! ✨ Bạn cần <strong>Trợ lý AI</strong> hỗ trợ thông tin gì tiếp theo?
                </div>
                <div class="ai-chatbot-suggestions">
                    <span class="ai-chip-title">💡 Gợi ý câu hỏi phổ biến:</span>
                    <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Hướng dẫn cách đăng bán sản phẩm?')">🛍️ Đăng bán sản phẩm như thế nào?</button>
                    <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Làm thế nào để kiểm tra đơn hàng đã mua?')">📦 Kiểm tra đơn hàng của tôi</button>
                    <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Phương thức thanh toán và nạp tiền vào ví?')">💳 Nạp tiền & Thanh toán thế nào?</button>
                    <button type="button" class="ai-chip" onclick="sendAiQuickTopic('Chính sách bảo mật và an toàn mua bán?')">🛡️ Chính sách mua bán an toàn</button>
                </div>
            </div>
        </div>
    `;
}

function sendAiQuickTopic(text) {
    appendUserMessage(text);
    simulateAiResponse(text);
}

function handleAiChatSubmit(e) {
    e.preventDefault();
    const input = document.getElementById('ai-chatbot-input');
    if (!input) return;

    const val = input.value.trim();
    if (!val) return;

    appendUserMessage(val);
    input.value = '';

    simulateAiResponse(val);
}

function appendUserMessage(text) {
    const msgBox = document.getElementById('ai-chatbot-messages');
    if (!msgBox) return;

    const userHtml = `
        <div class="ai-msg-row ai-msg-user">
            <div class="ai-msg-avatar">👤</div>
            <div class="ai-msg-content">
                <div class="ai-msg-bubble">${escapeHtml(text)}</div>
            </div>
        </div>
    `;
    msgBox.insertAdjacentHTML('beforeend', userHtml);
    msgBox.scrollTop = msgBox.scrollHeight;
}

function simulateAiResponse(queryText) {
    const msgBox = document.getElementById('ai-chatbot-messages');
    if (!msgBox) return;

    // Show Typing Indicator
    const typingId = 'typing-' + Date.now();
    const typingHtml = `
        <div id="${typingId}" class="ai-msg-row ai-msg-bot">
            <div class="ai-msg-avatar">🤖</div>
            <div class="ai-msg-content">
                <div class="ai-typing-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    `;
    msgBox.insertAdjacentHTML('beforeend', typingHtml);
    msgBox.scrollTop = msgBox.scrollHeight;

    // Generate smart mock UI response based on text keywords
    setTimeout(() => {
        const typingEl = document.getElementById(typingId);
        if (typingEl) typingEl.remove();

        let responseText = "";
        const lower = queryText.toLowerCase();

        if (lower.includes('đăng bán') || lower.includes('bán đồ') || lower.includes('đăng sản phẩm')) {
            responseText = "Để **đăng bán sản phẩm**:<br>1️⃣ Nhấn vào nút <strong>'Đăng Tin'</strong> trên thanh menu.<br>2️⃣ Điền đầy đủ thông tin tên, danh mục, giá bán, hình ảnh và mô tả sản phẩm.<br>3️⃣ Nhấn nút <strong>'Đăng Sản Phẩm'</strong> để hoàn tất!";
        } else if (lower.includes('đơn hàng') || lower.includes('kiểm tra đơn') || lower.includes('theo dõi')) {
            responseText = "Để **kiểm tra đơn hàng**:<br>👉 Bạn có thể truy cập mục <strong>'Đơn Hàng Của Tôi'</strong> trên góc trên tài khoản để xem trạng thái vận chuyển, chi tiết thanh toán và theo dõi mã vận đơn.";
        } else if (lower.includes('nạp tiền') || lower.includes('thanh toán') || lower.includes('ví')) {
            responseText = "Hệ thống hỗ trợ thanh toán qua <strong>Ví Điện Tử / Chuyển khoản QR (PayOS)</strong>.<br>💳 Bạn có thể nạp tiền vào ví hoặc thanh toán trực tiếp khi xác nhận đơn hàng!";
        } else if (lower.includes('bảo mật') || lower.includes('an toàn') || lower.includes('chính sách')) {
            responseText = "🛡️ **Cam kết an toàn mua bán**:<br>Tất cả giao dịch trên hệ thống Chợ Đồ Cũ đều được bảo vệ với mã hóa bảo mật, xác thực OTP và cơ chế khiếu nại trả hàng/hoàn tiền trong vòng 3 ngày.";
        } else {
            responseText = `Cảm ơn bạn đã đặt câu hỏi: <em>"${escapeHtml(queryText)}"</em>.<br>Hiện tại đây là <strong>giao diện bản dựng xem trước (UI Demo)</strong> của Trợ Lý AI. Hệ thống đang sẵn sàng để kết nối với bộ xử lý AI thông minh trong bước tiếp theo!`;
        }

        const botHtml = `
            <div class="ai-msg-row ai-msg-bot">
                <div class="ai-msg-avatar">🤖</div>
                <div class="ai-msg-content">
                    <div class="ai-msg-bubble">${responseText}</div>
                </div>
            </div>
        `;
        msgBox.insertAdjacentHTML('beforeend', botHtml);
        msgBox.scrollTop = msgBox.scrollHeight;
    }, 1000);
}

function escapeHtml(str) {
    return str
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>
