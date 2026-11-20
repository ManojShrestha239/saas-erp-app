import * as React from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/shared/utils/cn';

interface AccordionContextType {
  value: string | string[];
  onToggle: (itemValue: string) => void;
  isExpanded: (itemValue: string) => boolean;
}

const AccordionContext = React.createContext<AccordionContextType | null>(null);

export interface AccordionProps extends React.HTMLAttributes<HTMLDivElement> {
  type?: 'single' | 'multiple';
  defaultValue?: string | string[];
  value?: string | string[];
  onValueChange?: (value: string | string[]) => void;
}

export function Accordion({
  type = 'single',
  defaultValue,
  value: controlledValue,
  onValueChange,
  className,
  children,
  ...props
}: AccordionProps) {
  const [internalValue, setInternalValue] = React.useState<string | string[]>(
    controlledValue ?? defaultValue ?? (type === 'multiple' ? [] : '')
  );

  const activeValue = controlledValue !== undefined ? controlledValue : internalValue;

  const handleToggle = React.useCallback(
    (itemValue: string) => {
      let nextValue: string | string[];
      if (type === 'single') {
        nextValue = activeValue === itemValue ? '' : itemValue;
      } else {
        const arr = Array.isArray(activeValue) ? [...activeValue] : [];
        const index = arr.indexOf(itemValue);
        if (index > -1) {
          arr.splice(index, 1);
        } else {
          arr.push(itemValue);
        }
        nextValue = arr;
      }

      if (controlledValue === undefined) {
        setInternalValue(nextValue);
      }
      onValueChange?.(nextValue);
    },
    [activeValue, controlledValue, onValueChange, type]
  );

  const isExpanded = React.useCallback(
    (itemValue: string) => {
      if (Array.isArray(activeValue)) {
        return activeValue.includes(itemValue);
      }
      return activeValue === itemValue;
    },
    [activeValue]
  );

  return (
    <AccordionContext.Provider value={{ value: activeValue, onToggle: handleToggle, isExpanded }}>
      <div className={cn('divide-y divide-border border border-border rounded-lg bg-card', className)} {...props}>
        {children}
      </div>
    </AccordionContext.Provider>
  );
}

interface AccordionItemContextType {
  itemValue: string;
}

const AccordionItemContext = React.createContext<AccordionItemContextType | null>(null);

export interface AccordionItemProps extends React.HTMLAttributes<HTMLDivElement> {
  value: string;
}

export function AccordionItem({ value, className, children, ...props }: AccordionItemProps) {
  return (
    <AccordionItemContext.Provider value={{ itemValue: value }}>
      <div className={cn('overflow-hidden first:rounded-t-lg last:rounded-b-lg', className)} {...props}>
        {children}
      </div>
    </AccordionItemContext.Provider>
  );
}

export interface AccordionTriggerProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  children: React.ReactNode;
}

export function AccordionTrigger({ className, children, ...props }: AccordionTriggerProps) {
  const accordionContext = React.useContext(AccordionContext);
  const itemContext = React.useContext(AccordionItemContext);

  if (!accordionContext || !itemContext) {
    throw new Error('AccordionTrigger must be used within AccordionItem');
  }

  const expanded = accordionContext.isExpanded(itemContext.itemValue);
  const triggerId = `accordion-trigger-${itemContext.itemValue}`;
  const contentId = `accordion-content-${itemContext.itemValue}`;

  return (
    <button
      type="button"
      id={triggerId}
      aria-expanded={expanded}
      aria-controls={contentId}
      onClick={() => accordionContext.onToggle(itemContext.itemValue)}
      className={cn(
        'flex w-full items-center justify-between px-6 py-4 text-left text-sm font-semibold text-foreground transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
        className
      )}
      {...props}
    >
      <span className="flex-1 pr-4">{children}</span>
      <ChevronDown
        className={cn(
          'h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200',
          expanded && 'rotate-180 text-foreground'
        )}
        aria-hidden="true"
      />
    </button>
  );
}

export interface AccordionContentProps extends React.HTMLAttributes<HTMLDivElement> {
  children: React.ReactNode;
}

export function AccordionContent({ className, children, ...props }: AccordionContentProps) {
  const accordionContext = React.useContext(AccordionContext);
  const itemContext = React.useContext(AccordionItemContext);

  if (!accordionContext || !itemContext) {
    throw new Error('AccordionContent must be used within AccordionItem');
  }

  const expanded = accordionContext.isExpanded(itemContext.itemValue);
  const triggerId = `accordion-trigger-${itemContext.itemValue}`;
  const contentId = `accordion-content-${itemContext.itemValue}`;

  if (!expanded) {
    return null;
  }

  return (
    <div
      id={contentId}
      role="region"
      aria-labelledby={triggerId}
      className={cn('px-6 pb-4 pt-1 text-sm text-muted-foreground border-t border-border/20 bg-card', className)}
      {...props}
    >
      {children}
    </div>
  );
}
