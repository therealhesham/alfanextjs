import React from "react";
import { MaintenanceContractData } from "@/app/actions/maintenanceActions";
import { MessageSquare } from "lucide-react";

interface ContractTableRowProps {
  contract: MaintenanceContractData;
}

export default function ContractTableRow({ contract }: ContractTableRowProps) {
  const isContractActive = contract.end_date ? new Date(contract.end_date) >= new Date(new Date().setHours(0,0,0,0)) : false;

  return (
    <tr className={`border-b border-slate-100 hover:bg-slate-50 transition-colors ${!isContractActive || contract.is_hidden ? 'bg-slate-50/50 text-slate-500' : ''}`}>
      <td className="p-4 whitespace-nowrap font-bold text-[#1e293b]">
        #{contract.id}
      </td>
      <td className="p-4 font-semibold text-[#1e293b] max-w-[200px] truncate">
        {contract.project_name}
        {contract.is_guarantee && (
          <span className="mr-2 inline-flex items-center gap-1 bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-[10px]">
            ضمان
          </span>
        )}
      </td>
      <td className="p-4 truncate max-w-[150px]">
        {contract.client_name}
      </td>
      <td className="p-4 truncate max-w-[150px]">
        {contract.technician_name}
      </td>
      <td className="p-4 whitespace-nowrap">
        {contract.is_guarantee ? 'ضمان' : 'صيانة'}
      </td>
      <td className="p-4 whitespace-nowrap">
        {contract.start_date ? new Date(contract.start_date).toLocaleDateString('ar-SA') : '-'}
      </td>
      <td className="p-4 whitespace-nowrap">
        {contract.end_date ? new Date(contract.end_date).toLocaleDateString('ar-SA') : '-'}
      </td>
      <td className="p-4 font-semibold text-slate-700 whitespace-nowrap">
        {contract.total_amount ? `${contract.total_amount} ر.س` : '-'}
      </td>
      <td className="p-4">
        {contract.is_hidden ? (
          <span className="bg-slate-200 text-slate-600 px-2 py-1 rounded text-xs font-semibold">
            مؤرشف
          </span>
        ) : !isContractActive ? (
          <span className="bg-rose-100 text-rose-600 px-2 py-1 rounded text-xs font-semibold">
            منتهي
          </span>
        ) : (
          <span className="bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-xs font-semibold">
            ساري
          </span>
        )}
      </td>
      <td className="p-4">
        <button className="flex items-center gap-1.5 bg-gradient-to-br from-[#977e2b] to-[#b89635] text-white px-3 py-1.5 rounded-full text-xs font-semibold hover:shadow-lg transition-all hover:-translate-y-0.5">
          <MessageSquare size={14} />
          <span>ملاحظات</span>
          {contract.notes_count > 0 && (
            <span className="bg-white/30 px-1.5 rounded-full text-[10px]">
              {contract.notes_count}
            </span>
          )}
        </button>
      </td>
    </tr>
  );
}
