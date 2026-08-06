document.addEventListener('DOMContentLoaded', function () {
    let activeConversationId = null;
    let lastMessageId = 0;
    let pollTimer = null;
    let allConversations = [];

    // DOM Elements
    const chatCard = document.getElementById('chatCard');
    const conversationsList = document.getElementById('conversationsList');
    const searchConvInput = document.getElementById('searchConvInput');
    const noChatSelected = document.getElementById('noChatSelected');
    const chatContent = document.getElementById('chatContent');
    const chatPartnerName = document.getElementById('chatPartnerName');
    const chatPartnerAvatar = document.getElementById('chatPartnerAvatar');
    const chatProductBar = document.getElementById('chatProductBar');
    const chatProductImg = document.getElementById('chatProductImg');
    const chatProductTitle = document.getElementById('chatProductTitle');
    const chatProductPrice = document.getElementById('chatProductPrice');
    const chatProductLink = document.getElementById('chatProductLink');
    const messagesFeed = document.getElementById('messagesFeed');
    const chatSendForm = document.getElementById('chatSendForm');
    const chatMessageInput = document.getElementById('chatMessageInput');
    const btnAttachImg = document.getElementById('btnAttachImg');
    const chatFileInput = document.getElementById('chatFileInput');
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');
    const imagePreviewThumb = document.getElementById('imagePreviewThumb');
    const imagePreviewName = document.getElementById('imagePreviewName');
    const imagePreviewRemove = document.getElementById('imagePreviewRemove');
    const mobileBackBtn = document.getElementById('mobileBackBtn');

    // 1. Khởi tạo danh sách cuộc hội thoại
    loadConversations();

    // Nếu có tham số người nhận từ URL (ví dụ chuyển từ trang sản phẩm hoặc trang người bán)
    if (INIT_PARTNER_ID > 0) {
        startOrOpenConversation(INIT_PARTNER_ID, INIT_PRODUCT_ID);
    }

    // 2. Tải danh sách các cuộc hội thoại
    function loadConversations() {
        fetch('api_chat.php?action=list_conversations')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    allConversations = data.conversations;
                    renderConversationsList(allConversations);
                } else {
                    conversationsList.innerHTML = `<div style="padding:20px; color:#ef4444; text-align:center;">${data.message}</div>`;
                }
            })
            .catch(err => {
                conversationsList.innerHTML = `<div style="padding:20px; color:#ef4444; text-align:center;">Lỗi kết nối máy chủ.</div>`;
            });
    }

    // 3. Render danh sách cuộc hội thoại
    function renderConversationsList(list) {
        if (list.length === 0) {
            conversationsList.innerHTML = `
                <div style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                    Chưa có cuộc hội thoại nào.
                </div>`;
            return;
        }

        let html = '';
        list.forEach(conv => {
            const isActive = conv.id === activeConversationId ? 'active' : '';
            const productBadge = conv.product_name 
                ? `<div class="conv-product-badge">📦 ${escapeHtml(conv.product_name)}</div>` 
                : '';
            
            html += `
                <div class="conversation-item ${isActive}" data-id="${conv.id}">
                    <div class="conv-avatar-wrap">
                        <img src="${escapeHtml(conv.partner_avatar)}" class="conv-avatar" alt="Avatar">
                    </div>
                    <div class="conv-details">
                        <div class="conv-top">
                            <span class="conv-name">${escapeHtml(conv.partner_name)}</span>
                            <span class="conv-time">${conv.last_time}</span>
                        </div>
                        <div class="conv-preview">${conv.is_last_me ? 'Bạn: ' : ''}${escapeHtml(conv.last_message)}</div>
                        ${productBadge}
                    </div>
                </div>
            `;
        });

        conversationsList.innerHTML = html;

        // Gắn sự kiện click từng cuộc hội thoại
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.addEventListener('click', function () {
                const cid = parseInt(this.getAttribute('data-id'));
                selectConversation(cid);
            });
        });
    }

    // 4. Tìm kiếm cuộc hội thoại
    searchConvInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        const filtered = allConversations.filter(c => 
            c.partner_name.toLowerCase().includes(query) || 
            (c.product_name && c.product_name.toLowerCase().includes(query))
        );
        renderConversationsList(filtered);
    });

    // 5. Mở hoặc khởi tạo cuộc hội thoại mới với người bán
    function startOrOpenConversation(partnerId, productId) {
        const formData = new FormData();
        formData.append('partner_id', partnerId);
        if (productId > 0) formData.append('product_id', productId);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('api_chat.php?action=start', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': CSRF_TOKEN
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadConversations();
                selectConversation(data.conversation_id);
            } else {
                alert(data.message || 'Không thể bắt đầu chat với người này.');
            }
        })
        .catch(err => {
            console.error('Lỗi khởi tạo chat:', err);
        });
    }

    // 6. Chọn 1 cuộc hội thoại để chat
    function selectConversation(convId) {
        activeConversationId = convId;
        lastMessageId = 0;

        // Reset Polling timer cũ
        if (pollTimer) clearInterval(pollTimer);

        // UI updates
        noChatSelected.style.display = 'none';
        chatContent.style.display = 'flex';
        chatCard.classList.add('mobile-active-chat');

        // Update active class danh sách
        document.querySelectorAll('.conversation-item').forEach(el => {
            el.classList.toggle('active', parseInt(el.getAttribute('data-id')) === convId);
        });

        // Lấy thông tin cuộc hội thoại từ bộ nhớ local
        const conv = allConversations.find(c => c.id === convId);
        if (conv) {
            chatPartnerName.textContent = conv.partner_name;
            chatPartnerAvatar.src = conv.partner_avatar;

            if (conv.product_id) {
                chatProductBar.style.display = 'flex';
                chatProductTitle.textContent = conv.product_name;
                chatProductPrice.textContent = conv.product_price;
                chatProductImg.src = conv.product_image || 'assets/images/no-image.png';
                chatProductLink.href = `product_detail.php?id=${conv.product_id}`;
            } else {
                chatProductBar.style.display = 'none';
            }
        }

        // Tải lịch sử tin nhắn ban đầu
        messagesFeed.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;">Đang tải tin nhắn...</div>';
        fetchMessages(true);

        // Thiết lập AJAX Polling mỗi 2.5s
        pollTimer = setInterval(() => {
            fetchMessages(false);
        }, 2500);
    }

    // 7. Gọi API lấy tin nhắn (với after_id cho Polling)
    function fetchMessages(isInitial = false) {
        if (!activeConversationId) return;

        let url = `api_chat.php?action=get_messages&conversation_id=${activeConversationId}`;
        if (!isInitial && lastMessageId > 0) {
            url += `&after_id=${lastMessageId}`;
        }

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (isInitial) {
                        renderAllMessages(data.messages);
                    } else if (data.messages.length > 0) {
                        appendNewMessages(data.messages);
                    }
                }
            })
            .catch(err => {
                console.error('Lỗi polling tin nhắn:', err);
            });
    }

    // Render toàn bộ tin nhắn
    function renderAllMessages(messages) {
        if (messages.length === 0) {
            messagesFeed.innerHTML = `
                <div style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                    Hãy gửi lời chào để bắt đầu trò chuyện! 👋
                </div>`;
            lastMessageId = 0;
            return;
        }

        let html = '';
        messages.forEach(msg => {
            lastMessageId = Math.max(lastMessageId, msg.id);
            html += createMessageBubbleHtml(msg);
        });

        messagesFeed.innerHTML = html;
        scrollToBottom();
    }

    // Nối thêm tin nhắn mới vào feed khi Polling có dữ liệu mới
    function appendNewMessages(newMessages) {
        // Kiểm tra xem feed có đang trống hay không
        if (lastMessageId === 0) {
            messagesFeed.innerHTML = '';
        }

        newMessages.forEach(msg => {
            lastMessageId = Math.max(lastMessageId, msg.id);
            const msgNode = document.createElement('div');
            msgNode.innerHTML = createMessageBubbleHtml(msg);
            messagesFeed.appendChild(msgNode.firstElementChild);
        });

        // Cập nhật lại xem trước tin nhắn trong danh sách bên trái
        const lastMsg = newMessages[newMessages.length - 1];
        const conv = allConversations.find(c => c.id === activeConversationId);
        if (conv) {
            conv.last_message = lastMsg.content || '[Hình ảnh]';
            conv.last_time = lastMsg.time;
            conv.is_last_me = lastMsg.is_me;
            renderConversationsList(allConversations);
        }

        scrollToBottom();
    }

    // HTML template cho 1 bong bóng tin nhắn
    function createMessageBubbleHtml(msg) {
        const sideClass = msg.is_me ? 'me' : 'them';
        const imgHtml = msg.image 
            ? `<div><img src="${escapeHtml(msg.image)}" class="message-img" onclick="window.open(this.src)" title="Bấm để xem ảnh phóng to"></div>` 
            : '';
        const contentHtml = msg.content ? `<div>${escapeHtml(msg.content)}</div>` : '';

        return `
            <div class="message-row ${sideClass}">
                <div class="message-bubble">
                    ${contentHtml}
                    ${imgHtml}
                </div>
                <span class="message-time">${msg.time}</span>
            </div>
        `;
    }

    // 8. Tự động cuộn xuống tin nhắn mới nhất
    function scrollToBottom() {
        messagesFeed.scrollTop = messagesFeed.scrollHeight;
    }

    // 9. Xử lý đính kèm ảnh
    btnAttachImg.addEventListener('click', () => chatFileInput.click());

    chatFileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            imagePreviewName.textContent = file.name;
            const reader = new FileReader();
            reader.onload = function (e) {
                imagePreviewThumb.src = e.target.result;
                imagePreviewContainer.style.display = 'flex';
            };
            reader.readAsDataURL(file);
        }
    });

    imagePreviewRemove.addEventListener('click', function () {
        chatFileInput.value = '';
        imagePreviewContainer.style.display = 'none';
        imagePreviewThumb.src = '';
    });

    // 10. Gửi tin nhắn mới không bị reload trang
    chatSendForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!activeConversationId) return;

        const content = chatMessageInput.value.trim();
        const file = chatFileInput.files[0];

        if (!content && !file) return;

        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('conversation_id', activeConversationId);
        formData.append('content', content);
        if (file) {
            formData.append('chat_image', file);
        }
        formData.append('csrf_token', CSRF_TOKEN);

        // Reset input UI ngay lập tức cho trải nghiệm nhanh
        chatMessageInput.value = '';
        chatFileInput.value = '';
        imagePreviewContainer.style.display = 'none';

        fetch('api_chat.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': CSRF_TOKEN
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                appendNewMessages([data.message]);
            } else {
                alert(data.message || 'Lỗi gửi tin nhắn.');
            }
        })
        .catch(err => {
            alert('Lỗi kết nối khi gửi tin nhắn.');
        });
    });

    // Quay lại danh sách hội thoại trên điện thoại
    mobileBackBtn.addEventListener('click', function () {
        chatCard.classList.remove('mobile-active-chat');
    });

    // Hàm mã hóa chuỗi tránh XSS
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
