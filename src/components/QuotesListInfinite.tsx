'use client';

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { loadMoreQuotes } from '@/app/actions/quoteActions';
import QuoteActions from '@/components/QuoteActions';

export default function QuotesListInfinite({ initialQuotes }: { initialQuotes: any[] }) {
  const [quotes, setQuotes] = useState(initialQuotes);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [hasMore, setHasMore] = useState(initialQuotes.length === 20);
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');

  const isFirstRender = useRef(true);
  const observerTarget = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchQuery);
    }, 500);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  useEffect(() => {
    if (isFirstRender.current) {
      isFirstRender.current = false;
      return;
    }
    
    let isMounted = true;
    const fetchSearch = async () => {
      setLoading(true);
      const res = await loadMoreQuotes(1, 20, debouncedQuery);
      if (isMounted) {
        if (res.success && res.quotes) {
          setQuotes(res.quotes);
          setPage(1);
          setHasMore(res.quotes.length === 20);
        }
        setLoading(false);
      }
    };
    
    fetchSearch();
    return () => { isMounted = false; };
  }, [debouncedQuery]);

  const fetchMore = useCallback(async () => {
    if (loading || !hasMore) return;
    
    setLoading(true);
    const nextPage = page + 1;
    const res = await loadMoreQuotes(nextPage, 20, debouncedQuery);
    
    if (res.success && res.quotes && res.quotes.length > 0) {
      setQuotes(prev => [...prev, ...res.quotes]);
      setPage(nextPage);
      if (res.quotes.length < 20) {
        setHasMore(false);
      }
    } else {
      setHasMore(false);
    }
    setLoading(false);
  }, [page, loading, hasMore, debouncedQuery]);

  useEffect(() => {
    const observer = new IntersectionObserver(
      entries => {
        if (entries[0].isIntersecting && hasMore && !loading) {
          fetchMore();
        }
      },
      { threshold: 1.0 }
    );

    if (observerTarget.current) {
      observer.observe(observerTarget.current);
    }

    return () => observer.disconnect();
  }, [fetchMore, hasMore, loading]);

  return (
    <>
      <div className="p-4 md:p-6 border-b border-[var(--color-border)] bg-white">
        <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
          <div className="flex items-center gap-2 overflow-x-auto w-full md:w-auto pb-2 md:pb-0">
            <div className="filter-chip cursor-pointer bg-[var(--color-gold-light)] text-[var(--color-gold)] border-[var(--color-gold)] px-4 py-1.5 rounded-full text-sm font-medium">
              الكل
            </div>
          </div>
          
          <div className="relative w-full md:w-64">
            <input 
              type="text" 
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="بحث برقم، عميل، ماركة..." 
              className="w-full pl-10 pr-4 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] text-sm"
            />
            <i className="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-[var(--color-medium-gray)]"></i>
          </div>
        </div>
      </div>

      <div className="table-container">
        <table className="modern-table min-w-[1000px]">
          <thead>
            <tr>
              <th className="table-header w-24">رقم العرض</th>
              <th className="table-header w-40">التاريخ</th>
              <th className="table-header">العميل</th>
              <th className="table-header w-32">قيمة العرض</th>
              <th className="table-header w-32">البراند</th>
              <th className="table-header w-32">بواسطة</th>
              <th className="table-header w-40">الحالة</th>
              <th className="table-header w-48 text-center">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            {quotes.length > 0 ? quotes.map((quote) => {
              const dateOptions: Intl.DateTimeFormatOptions = { 
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', hour12: true
              };
              const date = quote.date ? new Intl.DateTimeFormat('ar-SA', dateOptions).format(new Date(quote.date)) : '';
              
              const price = quote.totalPrice ? `${quote.totalPrice} ر.س` : '0 ر.س';
              
              // Simple color mapping based on status name keywords for demonstration
              let statusColor = '#6b7280'; // gray
              let statusIcon = 'fa-circle';
              if (quote.statusName.includes('معتمد') || quote.statusName.includes('موافق')) { statusColor = '#16a34a'; statusIcon = 'fa-check-circle'; }
              else if (quote.statusName.includes('مرفوض') || quote.statusName.includes('ملغي')) { statusColor = '#dc2626'; statusIcon = 'fa-times-circle'; }
              else if (quote.statusName.includes('مراجعة') || quote.statusName.includes('انتظار') || quote.statusName.includes('اعتماد')) { statusColor = '#f59e0b'; statusIcon = 'fa-clock'; }

              return (
                <tr key={quote.id} className="table-row table-row-white">
                  <td className="table-cell">
                    <div className="flex flex-col gap-1 items-center">
                      <span className="quote-id">#{quote.quoteNumber}</span>
                      <span className="text-[10px] text-[var(--color-medium-gray)]">نظام: {quote.id}</span>
                    </div>
                  </td>
                  <td className="table-cell">
                    <span className="quote-date" dir="ltr">{date}</span>
                  </td>
                  <td className="table-cell">
                    <span className="quote-client">{quote.clientName}</span>
                  </td>
                  <td className="table-cell">
                    <span className="quote-price">{price}</span>
                  </td>
                  <td className="table-cell">
                    <span className="quote-brand">{quote.brandName}</span>
                  </td>
                  <td className="table-cell">
                    <span className="quote-user">{quote.creatorName}</span>
                  </td>
                  <td className="table-cell">
                    <div className="status-control">
                      <button 
                        type="button" 
                        className="status-badge"
                        style={{ borderColor: statusColor, color: statusColor, backgroundColor: `${statusColor}15` }}
                      >
                        <span className="flex items-center gap-2">
                          <i className={`status-icon fas ${statusIcon}`}></i>
                          <span className="status-label-text">{quote.statusName}</span>
                        </span>
                        <i className="fas fa-angle-down text-xs opacity-70 mr-2"></i>
                      </button>
                    </div>
                  </td>
                  <td className="table-cell">
                    <QuoteActions quoteId={quote.id} />
                  </td>
                </tr>
              );
            }) : (
              <tr>
                <td colSpan={8} className="text-center py-8 text-[var(--color-medium-gray)]">
                  لا توجد عروض أسعار حتى الآن
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      
      {/* Loading Indicator for Infinite Scroll */}
      {hasMore && (
        <div ref={observerTarget} className="flex justify-center items-center py-6">
          {loading ? (
            <div className="flex flex-col items-center gap-2 text-[var(--color-gold)]">
              <i className="fas fa-spinner fa-spin text-2xl"></i>
              <span className="text-sm font-medium">جاري تحميل المزيد...</span>
            </div>
          ) : (
            <div className="h-10"></div> /* Invisible spacer for intersection observer */
          )}
        </div>
      )}
      {!hasMore && quotes.length > 0 && (
        <div className="text-center py-4 text-sm text-[var(--color-medium-gray)] bg-gray-50 border-t border-[var(--color-border)]">
          تم تحميل جميع العروض ({quotes.length} عرض)
        </div>
      )}
    </>
  );
}
