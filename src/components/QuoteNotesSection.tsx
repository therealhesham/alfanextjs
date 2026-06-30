'use client';

import React, { useState, useEffect } from 'react';
import { getQuoteNotes, addQuoteNote } from '@/app/actions/noteActions';

interface Note {
  id: string;
  text: string;
  authorName: string;
  createdAt: Date;
}

export default function QuoteNotesSection({ quoteId, noCard = false }: { quoteId: string, noCard?: boolean }) {
  const [notes, setNotes] = useState<Note[]>([]);
  const [newNoteText, setNewNoteText] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchNotes = async () => {
      setLoading(true);
      const res = await getQuoteNotes(quoteId);
      if (res.success && res.notes) {
        setNotes(res.notes);
      } else {
        setError(res.error || 'فشل في تحميل الملاحظات');
      }
      setLoading(false);
    };

    fetchNotes();
  }, [quoteId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newNoteText.trim()) return;

    setSubmitting(true);
    setError(null);

    const res = await addQuoteNote(quoteId, newNoteText);
    
    if (res.success && res.note) {
      // Add the new note to the top of the list
      setNotes([res.note, ...notes]);
      setNewNoteText('');
    } else {
      setError(res.error || 'فشل في إضافة الملاحظة');
    }

    setSubmitting(false);
  };

  return (
    <div className={noCard ? "" : "card mt-6"}>
      {!noCard && (
        <h2 className="text-xl font-bold mb-4 flex items-center gap-2">
          <i className="fas fa-comments text-[var(--color-gold)]"></i>
          ملاحظات العرض
        </h2>
      )}

      {error && (
        <div className="bg-red-50 text-red-600 p-3 rounded-md mb-4 text-sm border border-red-100">
          {error}
        </div>
      )}

      {/* Add Note Form */}
      <form onSubmit={handleSubmit} className="mb-6">
        <div className="mb-3">
          <textarea
            value={newNoteText}
            onChange={(e) => setNewNoteText(e.target.value)}
            placeholder="اكتب ملاحظة جديدة هنا..."
            className="w-full p-3 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] resize-none"
            rows={3}
            disabled={submitting}
          ></textarea>
        </div>
        <div className="flex justify-end">
          <button
            type="submit"
            className="btn-gold"
            disabled={submitting || !newNoteText.trim()}
          >
            {submitting ? (
              <>
                <i className="fas fa-spinner fa-spin ml-2"></i>
                جاري الإضافة...
              </>
            ) : (
              <>
                <i className="fas fa-paper-plane ml-2"></i>
                إضافة الملاحظة
              </>
            )}
          </button>
        </div>
      </form>

      {/* Notes List */}
      <div className="space-y-4">
        {loading ? (
          <div className="flex justify-center items-center py-6 text-[var(--color-gold)]">
            <i className="fas fa-spinner fa-spin text-2xl"></i>
          </div>
        ) : notes.length > 0 ? (
          notes.map((note) => {
            const dateOptions: Intl.DateTimeFormatOptions = { 
              year: 'numeric', month: '2-digit', day: '2-digit',
              hour: '2-digit', minute: '2-digit', hour12: true
            };
            const formattedDate = new Intl.DateTimeFormat('ar-SA', dateOptions).format(new Date(note.createdAt));

            return (
              <div key={note.id} className="bg-gray-50 border border-[var(--color-border)] rounded-lg p-4">
                <div className="flex justify-between items-start mb-2 border-b border-gray-200 pb-2">
                  <div className="font-semibold text-[var(--color-dark-gray)]">
                    <i className="fas fa-user-circle text-gray-400 ml-1"></i>
                    {note.authorName}
                  </div>
                  <div className="text-xs text-[var(--color-medium-gray)]" dir="ltr">
                    {formattedDate}
                  </div>
                </div>
                <div className="text-[var(--color-dark-gray)] text-sm whitespace-pre-wrap">
                  {note.text}
                </div>
              </div>
            );
          })
        ) : (
          <div className="text-center py-6 text-[var(--color-medium-gray)] bg-gray-50 rounded-lg border border-dashed border-gray-300">
            لا توجد ملاحظات على هذا العرض حتى الآن.
          </div>
        )}
      </div>
    </div>
  );
}
