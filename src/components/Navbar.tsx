'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { FileText, Settings, ShieldCheck } from 'lucide-react';

export default function Navbar() {
  const pathname = usePathname();

  // Highlight Quotes if pathname is / or starts with /quotes
  const isQuotesActive = pathname === '/' || pathname.startsWith('/quotes');
  const isMaintenanceActive = pathname.startsWith('/maintenance');

  return (
    <nav className="bg-[#2c2c2c] text-white shadow-md border-b-4 border-[#977e2b]">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          <div className="flex items-center gap-8">
            {/* Logo */}
            <Link href="/" className="flex items-center gap-2 text-white font-extrabold text-xl tracking-wide group">
              <div className="w-9 h-9 rounded-lg bg-[#977e2b] flex items-center justify-center shadow-md group-hover:bg-[#b89635] transition-all">
                <span className="text-white text-base font-black">أ</span>
              </div>
              <span className="bg-gradient-to-r from-white to-gray-300 bg-clip-text text-transparent group-hover:to-white transition-all font-sans">
                ألفا الذهبية
              </span>
            </Link>

            {/* Links */}
            <div className="hidden md:flex items-center space-x-4 space-x-reverse">
              <Link 
                href="/" 
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-bold transition-all ${
                  isQuotesActive 
                    ? 'bg-[#977e2b] text-white shadow-md shadow-[#977e2b]/30' 
                    : 'text-gray-300 hover:text-white hover:bg-white/5'
                }`}
              >
                <FileText size={16} />
                <span>عروض الأسعار</span>
              </Link>

              <Link 
                href="/maintenance" 
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-bold transition-all ${
                  isMaintenanceActive 
                    ? 'bg-[#977e2b] text-white shadow-md shadow-[#977e2b]/30' 
                    : 'text-gray-300 hover:text-white hover:bg-white/5'
                }`}
              >
                <Settings size={16} />
                <span>عقود الصيانة</span>
              </Link>
            </div>
          </div>

          {/* Left info badge */}
          <div className="flex items-center gap-4">
            <div className="hidden sm:flex items-center gap-2 text-xs bg-white/5 px-3 py-1.5 rounded-full border border-white/10 text-gray-300">
              <ShieldCheck size={14} className="text-[#977e2b]" />
              <span className="font-semibold">لوحة الإشراف</span>
            </div>
          </div>
        </div>
      </div>

      {/* Mobile nav links */}
      <div className="md:hidden border-t border-white/5 bg-[#252525]">
        <div className="flex justify-around py-2">
          <Link 
            href="/" 
            className={`flex flex-col items-center gap-1 py-1.5 px-4 rounded-lg text-xs font-bold transition-all ${
              isQuotesActive 
                ? 'text-[#977e2b]' 
                : 'text-gray-400 hover:text-white'
            }`}
          >
            <FileText size={18} />
            <span>عروض الأسعار</span>
          </Link>

          <Link 
            href="/maintenance" 
            className={`flex flex-col items-center gap-1 py-1.5 px-4 rounded-lg text-xs font-bold transition-all ${
              isMaintenanceActive 
                ? 'text-[#977e2b]' 
                : 'text-gray-400 hover:text-white'
            }`}
          >
            <Settings size={18} />
            <span>عقود الصيانة</span>
          </Link>
        </div>
      </div>
    </nav>
  );
}
