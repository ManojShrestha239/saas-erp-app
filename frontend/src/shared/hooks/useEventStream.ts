import { useCallback, useEffect, useRef, useState } from 'react';

export type EventStreamConnectionState = 'connecting' | 'connected' | 'disconnected';

export interface UseEventStreamOptions {
  url?: string;
  enabled?: boolean;
  listeners?: Record<string, (data: unknown) => void>;
  onEvent?: (eventName: string, data: unknown) => void;
  reconnectIntervalMs?: number;
  maxReconnectAttempts?: number;
}

export interface UseEventStreamReturn {
  connectionState: EventStreamConnectionState;
  lastEventId: string | null;
  error: Event | null;
  reconnect: () => void;
}

export function useEventStream({
  url = '/api/v1/stream',
  enabled = true,
  listeners = {},
  onEvent,
  reconnectIntervalMs = 3000,
  maxReconnectAttempts = 5,
}: UseEventStreamOptions = {}): UseEventStreamReturn {
  const [connectionState, setConnectionState] =
    useState<EventStreamConnectionState>('disconnected');
  const [lastEventId, setLastEventId] = useState<string | null>(null);
  const [error, setError] = useState<Event | null>(null);

  const eventSourceRef = useRef<EventSource | null>(null);
  const reconnectAttemptsRef = useRef<number>(0);
  const reconnectTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const listenersRef = useRef(listeners);
  listenersRef.current = listeners;

  const onEventRef = useRef(onEvent);
  onEventRef.current = onEvent;

  const connect = useCallback(() => {
    if (!enabled || typeof window === 'undefined') {
      return;
    }

    // Clean up any existing connection or scheduled reconnect
    if (eventSourceRef.current) {
      eventSourceRef.current.close();
      eventSourceRef.current = null;
    }
    if (reconnectTimeoutRef.current) {
      clearTimeout(reconnectTimeoutRef.current);
      reconnectTimeoutRef.current = null;
    }

    setConnectionState('connecting');

    try {
      const sse = new EventSource(url, { withCredentials: true });
      eventSourceRef.current = sse;

      sse.onopen = () => {
        setConnectionState('connected');
        setError(null);
        reconnectAttemptsRef.current = 0;
      };

      sse.onerror = (err: Event) => {
        setError(err);
        setConnectionState('disconnected');
        sse.close();
        eventSourceRef.current = null;

        // Auto-reconnect with exponential backoff if within limits
        if (reconnectAttemptsRef.current < maxReconnectAttempts) {
          const timeout = reconnectIntervalMs * Math.pow(1.5, reconnectAttemptsRef.current);
          reconnectAttemptsRef.current += 1;
          reconnectTimeoutRef.current = setTimeout(() => {
            connect();
          }, timeout);
        }
      };

      const handleEventMessage = (eventName: string, e: MessageEvent) => {
        if (e.lastEventId) {
          setLastEventId(e.lastEventId);
        }

        let parsedData: unknown = e.data;
        try {
          if (typeof e.data === 'string') {
            parsedData = JSON.parse(e.data);
          }
        } catch {
          parsedData = e.data;
        }

        // Call global event handler if supplied
        onEventRef.current?.(eventName, parsedData);

        // Call specific event listener if supplied
        const handler = listenersRef.current[eventName];
        if (handler) {
          handler(parsedData);
        }
      };

      // Listen for custom named events in listeners object
      const registeredEvents = new Set([
        'connected',
        'heartbeat',
        'low_stock_alert',
        ...Object.keys(listenersRef.current),
      ]);

      registeredEvents.forEach((eventName) => {
        sse.addEventListener(eventName, (e: Event) => {
          handleEventMessage(eventName, e as MessageEvent);
        });
      });

      // Default message listener
      sse.onmessage = (e: MessageEvent) => {
        handleEventMessage('message', e);
      };
    } catch (err) {
      setConnectionState('disconnected');
      setError(err instanceof Event ? err : new Event('error'));
    }
  }, [enabled, url, reconnectIntervalMs, maxReconnectAttempts]);

  const reconnect = useCallback(() => {
    reconnectAttemptsRef.current = 0;
    connect();
  }, [connect]);

  useEffect(() => {
    if (enabled) {
      connect();
    } else {
      if (eventSourceRef.current) {
        eventSourceRef.current.close();
        eventSourceRef.current = null;
      }
      setConnectionState('disconnected');
    }

    return () => {
      if (eventSourceRef.current) {
        eventSourceRef.current.close();
        eventSourceRef.current = null;
      }
      if (reconnectTimeoutRef.current) {
        clearTimeout(reconnectTimeoutRef.current);
        reconnectTimeoutRef.current = null;
      }
    };
  }, [enabled, connect]);

  return {
    connectionState,
    lastEventId,
    error,
    reconnect,
  };
}
