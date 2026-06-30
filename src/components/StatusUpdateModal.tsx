'use client';

import React, { useState, useEffect } from 'react';
import { getQuoteStatus, updateQuoteStatus } from '../app/actions/quoteActions';

interface StatusUpdateModalProps {
  isOpen: boolean;
  onClose: () => void;
  quoteId: string;
  currentStatusLabel?: string;
  onStatusUpdated?: (newStatus: string) => void;
}

const STATUS_FLOW = [
  "اعتماد العرض من الادارة",
  "اعتماد العرض من العميل",
  "اعتماد العقد من الادارة",
  "اعتماد العقد من العميل",
  "سداد الدفعة الاولى",
  "طلب مواد المرحلة الاولى",
  "تسليم مواد المرحلة الأولى",
  "استلام مواد المرحلة الأولى",
  "محضر استلام المرحلة الأولى"
];

export default function StatusUpdateModal({
  isOpen,
  onClose,
  quoteId,
  currentStatusLabel: initialStatusLabel,
  onStatusUpdated
}: StatusUpdateModalProps) {
  const [loading, setLoading] = useState(false);
  const [fetching, setFetching] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');
  const [activeStatus, setActiveStatus] = useState<string | undefined>(initialStatusLabel);
  
  useEffect(() => {
    if (isOpen) {
      const fetchStatus = async () => {
        setFetching(true);
        const fetchedStatus = await getQuoteStatus(quoteId);
        if (fetchedStatus) {
          setActiveStatus(fetchedStatus);
        } else {
          setActiveStatus("اعتماد العرض من الادارة"); // Default
        }
        setFetching(false);
      };
      fetchStatus();
    }
  }, [isOpen, quoteId]);

  if (!isOpen) return null;

  // For simulation, let's say the current index is 0 if not provided
  let currentIndex = STATUS_FLOW.findIndex(s => s === activeStatus);
  if (currentIndex === -1) currentIndex = 0; // Default to first step if unknown

  const nextStatus = currentIndex < STATUS_FLOW.length - 1 ? STATUS_FLOW[currentIndex + 1] : null;
  const isCancelled = activeStatus && activeStatus.includes('ملغي');

  const handleUpdate = async (status: string, isCancel = false) => {
    if (isCancel && !window.confirm('هل أنت متأكد من رغبتك في إلغاء عرض السعر هذا وتحويل حالته إلى ملغي؟')) {
      return;
    }
    
    setLoading(true);
    
    try {
      const result = await updateQuoteStatus(quoteId, status);
      
      if (result.success) {
        alert(`تم التحديث بنجاح إلى: ${status}`);
        setActiveStatus(status);
        if (onStatusUpdated) onStatusUpdated(status);
        // onClose(); // Let's keep it open to show the new timeline, user can close it.
      } else {
        alert(result.error || 'حدث خطأ أثناء التحديث.');
      }
    } catch (error) {
      alert('حدث خطأ أثناء التحديث.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md overflow-hidden flex flex-col max-h-[90vh]">
        
        {/* Header */}
        <div className="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
          <div className="text-lg font-bold text-gray-800">تحديث حالة العرض</div>
          <button 
            onClick={onClose}
            className="text-gray-500 hover:bg-gray-200 hover:text-gray-800 rounded-md p-1 transition-colors"
          >
            <i className="fas fa-times text-xl"></i>
          </button>
        </div>

        {/* Content */}
        <div className="p-5 overflow-y-auto">
          
          {/* Actions */}
          {nextStatus && !isCancelled ? (
            <div className="flex items-center justify-between bg-blue-50/50 p-4 rounded-lg border border-blue-100 mb-4">
              <div className="text-sm">
                <span className="text-gray-500 block mb-1">الحالة القادمة:</span>
                <span className="font-bold text-gray-800">{nextStatus}</span>
              </div>
              <button 
                onClick={() => handleUpdate(nextStatus)}
                disabled={loading}
                className="bg-[#9c842b] hover:bg-[#857022] text-white px-4 py-2 rounded-md font-bold transition-colors flex items-center gap-2"
              >
                {loading ? <i className="fas fa-spinner fa-spin"></i> : <i className="fas fa-check"></i>}
                إتمام الحالة
              </button>
            </div>
          ) : (
            <div className="text-center text-gray-500 text-sm mb-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
              لا توجد حالة قادمة لهذا العرض.
            </div>
          )}

          {!isCancelled && (
            <button 
              onClick={() => handleUpdate('ملغي', true)}
              disabled={loading}
              className="w-full mb-6 border border-red-200 text-red-600 bg-red-50 hover:bg-red-100 py-2 rounded-md font-bold flex justify-center items-center gap-2 transition-colors border-dashed"
            >
              <i className="fas fa-times-circle"></i>
              إلغاء عرض السعر (تحويل لملغي)
            </button>
          )}

          {/* Timeline */}
          {fetching ? (
            <div className="flex justify-center items-center py-10">
              <i className="fas fa-spinner fa-spin text-amber-500 text-3xl"></i>
            </div>
          ) : (
            <div>
              <div className="flex justify-between items-center mb-4">
                <span className="text-sm font-bold text-gray-800">مسار الحالة</span>
                <span className="text-xs text-gray-500">
                  الحالة الحالية: {activeStatus || 'لا توجد حالة'}
                </span>
              </div>
            
            <div className="relative pl-4 border-r-2 border-gray-200 pr-4 space-y-6 text-right dir-rtl">
              {STATUS_FLOW.map((status, idx) => {
                let stateLabel = 'pending';
                let metaText = 'قادمة';
                let dotColor = 'bg-gray-300 border-white';
                let textColor = 'text-gray-500';

                if (idx < currentIndex) {
                  stateLabel = 'completed';
                  metaText = 'منتهية';
                  dotColor = 'bg-green-600 border-white opacity-70';
                  textColor = 'text-gray-800';
                } else if (idx === currentIndex) {
                  stateLabel = 'current';
                  metaText = 'جارية';
                  dotColor = 'bg-green-600 border-green-200 shadow-[0_0_0_4px_rgba(22,163,74,0.2)]';
                  textColor = 'text-green-700';
                } else if (idx === currentIndex + 1) {
                  stateLabel = 'next';
                  metaText = 'قادمة';
                  dotColor = 'bg-amber-500 border-amber-100';
                  textColor = 'text-amber-600';
                }

                return (
                  <div key={idx} className="relative group">
                    <div className={`absolute -right-[23px] top-1.5 w-3.5 h-3.5 rounded-full border-2 z-10 transition-colors ${dotColor}`}></div>
                    <div>
                      <div className={`font-bold text-sm transition-colors ${textColor}`}>
                        {status}
                      </div>
                      <div className="text-xs text-gray-400 mt-0.5">{metaText}</div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
          )}

        </div>

        {/* Footer */}
        <div className="p-4 border-t border-gray-200 bg-gray-50 flex gap-3">
          <button 
            onClick={onClose}
            className="flex-1 bg-gray-500 text-white py-2.5 rounded-md font-bold hover:bg-gray-600 transition-colors flex justify-center items-center gap-2"
          >
            <i className="fas fa-times"></i>
            إغلاق
          </button>
        </div>
      </div>
    </div>
  );
}
