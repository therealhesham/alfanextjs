'use server';

import { PrismaClient } from '@prisma/client';
import { revalidatePath } from 'next/cache';

const prisma = new PrismaClient();

export async function addQuoteNote(quoteIdStr: string, noteText: string, authorIdStr: string = '1') {
  try {
    if (!noteText || noteText.trim() === '') {
      return { success: false, error: 'يرجى إدخال نص للملاحظة' };
    }

    if (noteText.length > 2000) {
      return { success: false, error: 'نص الملاحظة طويل جداً (الحد الأقصى 2000 حرف)' };
    }

    const quoteId = BigInt(quoteIdStr);
    const authorId = BigInt(authorIdStr); // Defaulting to 1 for now, similar to how createQuote does

    const newNote = await prisma.quote_notes.create({
      data: {
        quote_id: quoteId,
        author_user_id: authorId,
        note_text: noteText,
      },
      include: {
        users: {
          select: {
            name: true
          }
        }
      }
    });

    // Revalidate the quote page if it exists
    revalidatePath(`/quotes/${quoteIdStr}`);
    
    return { 
      success: true, 
      note: {
        id: newNote.id.toString(),
        text: newNote.note_text,
        authorName: newNote.users?.name || 'مستخدم غير معروف',
        createdAt: newNote.created_at,
      }
    };
  } catch (error) {
    console.error('Error adding quote note:', error);
    return { success: false, error: 'حدث خطأ أثناء حفظ الملاحظة' };
  }
}

export async function getQuoteNotes(quoteIdStr: string) {
  try {
    const quoteId = BigInt(quoteIdStr);
    
    const notes = await prisma.quote_notes.findMany({
      where: {
        quote_id: quoteId,
      },
      include: {
        users: {
          select: {
            name: true,
          }
        }
      },
      orderBy: {
        created_at: 'desc'
      }
    });

    return { 
      success: true, 
      notes: notes.map((note) => ({
        id: note.id.toString(),
        text: note.note_text,
        authorName: note.users?.name || 'مستخدم غير معروف',
        createdAt: note.created_at,
      }))
    };
  } catch (error) {
    console.error('Error fetching quote notes:', error);
    return { success: false, notes: [], error: 'حدث خطأ أثناء جلب الملاحظات' };
  }
}
