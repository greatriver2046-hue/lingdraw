class WebSocketManager {
    constructor() {
        this.socket = null;
        this.url = '';
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectInterval = 3000;
        this.listeners = new Map();
        this.isConnecting = false;
        this.token = '';
        this.clientId = '';
    }

    connect(url, token) {
        if (this.socket && this.socket.readyState === WebSocket.OPEN) return;
        if (this.isConnecting) return;

        this.url = url;
        this.token = token;
        this.isConnecting = true;

        console.log('Connecting to WebSocket:', url);
        this.socket = new WebSocket(url);

        this.socket.onopen = () => {
            console.log('WebSocket connected');
            this.isConnecting = false;
            this.reconnectAttempts = 0;
            
            // Bind user if token exists
            if (this.token) {
                this.send({
                    type: 'bind',
                    token: this.token
                });
            }

            // Start heartbeat
            this.startHeartbeat();
        };

        this.socket.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                this.handleMessage(data);
            } catch (e) {
                console.error('WebSocket message parse error:', e);
            }
        };

        this.socket.onclose = () => {
            console.log('WebSocket disconnected');
            this.isConnecting = false;
            this.stopHeartbeat();
            this.reconnect();
        };

        this.socket.onerror = (error) => {
            console.error('WebSocket error:', error);
            this.isConnecting = false;
        };
    }

    reconnect() {
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log(`Reconnecting... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
            setTimeout(() => {
                this.connect(this.url, this.token);
            }, this.reconnectInterval);
        }
    }

    send(data) {
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify(data));
        } else {
            console.warn('WebSocket is not open. Message not sent:', data);
        }
    }

    handleMessage(data) {
        if (data.type === 'init') {
            this.clientId = data.client_id;
        }

        const listeners = this.listeners.get(data.type) || [];
        listeners.forEach(callback => callback(data));
        
        // Also trigger a global listener if needed
        const allListeners = this.listeners.get('*') || [];
        allListeners.forEach(callback => callback(data));
    }

    on(type, callback) {
        if (!this.listeners.has(type)) {
            this.listeners.set(type, []);
        }
        this.listeners.get(type).push(callback);
    }

    off(type, callback) {
        if (!this.listeners.has(type)) return;
        const list = this.listeners.get(type);
        const index = list.indexOf(callback);
        if (index !== -1) {
            list.splice(index, 1);
        }
    }

    startHeartbeat() {
        this.heartbeatTimer = setInterval(() => {
            this.send({ type: 'ping' });
        }, 30000);
    }

    stopHeartbeat() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }

    disconnect() {
        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }
    }
}

export const wsManager = new WebSocketManager();
