import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useEventStream } from '../useEventStream';

class MockEventSource {
  static instances: MockEventSource[] = [];
  url: string;
  withCredentials?: boolean;
  onopen: (() => void) | null = null;
  onerror: ((err: Event) => void) | null = null;
  onmessage: ((e: MessageEvent) => void) | null = null;
  listeners: Record<string, ((e: Event) => void)[]> = {};
  closed = false;

  constructor(url: string, init?: { withCredentials?: boolean }) {
    this.url = url;
    this.withCredentials = init?.withCredentials;
    MockEventSource.instances.push(this);
  }

  addEventListener(type: string, listener: (e: Event) => void): void {
    if (!this.listeners[type]) {
      this.listeners[type] = [];
    }
    this.listeners[type].push(listener);
  }

  removeEventListener(type: string, listener: (e: Event) => void): void {
    if (this.listeners[type]) {
      this.listeners[type] = this.listeners[type].filter((l) => l !== listener);
    }
  }

  close(): void {
    this.closed = true;
  }

  emit(type: string, data: unknown, lastEventId?: string): void {
    const event = new MessageEvent(type, {
      data: typeof data === 'string' ? data : JSON.stringify(data),
      lastEventId: lastEventId ?? 'test-id-1',
    });

    const handlers = this.listeners[type] || [];
    handlers.forEach((h) => h(event));

    if (type === 'message' && this.onmessage) {
      this.onmessage(event);
    }
  }
}

describe('useEventStream', () => {
  const originalEventSource = globalThis.EventSource;

  function getInstance(index = 0): MockEventSource {
    const instance = MockEventSource.instances[index];
    if (!instance) {
      throw new Error(`Expected MockEventSource at index ${index}`);
    }
    return instance;
  }

  beforeEach(() => {
    MockEventSource.instances = [];
    // @ts-expect-error Mocking global EventSource
    globalThis.EventSource = MockEventSource;
  });

  afterEach(() => {
    globalThis.EventSource = originalEventSource;
    vi.restoreAllMocks();
  });

  it('initializes in connecting state and connects when enabled', () => {
    const { result } = renderHook(() => useEventStream({ url: '/api/v1/stream' }));

    expect(MockEventSource.instances.length).toBe(1);
    const instance = getInstance(0);
    expect(instance.url).toBe('/api/v1/stream');

    act(() => {
      instance.onopen?.();
    });

    expect(result.current.connectionState).toBe('connected');
  });

  it('invokes registered listeners and updates lastEventId when events arrive', () => {
    const handleLowStock = vi.fn();
    const handleGlobalEvent = vi.fn();

    const { result } = renderHook(() =>
      useEventStream({
        listeners: {
          low_stock_alert: handleLowStock,
        },
        onEvent: handleGlobalEvent,
      })
    );

    const instance = getInstance(0);
    act(() => {
      instance.onopen?.();
    });

    const payload = {
      product_id: 'prod-1',
      sku: 'WIDGET-01',
      quantity_on_hand: 2,
      low_stock_threshold: 10,
    };

    act(() => {
      instance.emit('low_stock_alert', payload, 'event-123');
    });

    expect(handleLowStock).toHaveBeenCalledWith(payload);
    expect(handleGlobalEvent).toHaveBeenCalledWith('low_stock_alert', payload);
    expect(result.current.lastEventId).toBe('event-123');
  });

  it('handles error and updates connectionState to disconnected', () => {
    const { result } = renderHook(() => useEventStream({ reconnectIntervalMs: 5000 }));

    const instance = getInstance(0);

    act(() => {
      instance.onerror?.(new Event('error'));
    });

    expect(result.current.connectionState).toBe('disconnected');
    expect(instance.closed).toBe(true);
  });

  it('closes EventSource on unmount', () => {
    const { unmount } = renderHook(() => useEventStream());
    const instance = getInstance(0);

    unmount();
    expect(instance.closed).toBe(true);
  });
});
