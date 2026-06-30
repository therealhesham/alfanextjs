"use client";

import { useState, useRef, useEffect } from 'react';
import Image from 'next/image';
import { useRouter } from 'next/navigation';
import styles from './login.module.css';

export default function LoginPage() {
    const router = useRouter();
    const [phone, setPhone] = useState('');
    const [step, setStep] = useState(1); // 1: phone, 2: otp
    const [otp, setOtp] = useState(['', '', '', '']);
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });
    const [timeLeft, setTimeLeft] = useState(0);
    const otpRefs = [
        useRef<HTMLInputElement>(null), 
        useRef<HTMLInputElement>(null), 
        useRef<HTMLInputElement>(null), 
        useRef<HTMLInputElement>(null)
    ];
    
    useEffect(() => {
        let timerId: NodeJS.Timeout;
        if (timeLeft > 0) {
            timerId = setTimeout(() => {
                setTimeLeft(timeLeft - 1);
            }, 1000);
        }
        return () => {
            if (timerId) clearTimeout(timerId);
        };
    }, [timeLeft]);

    const formatTime = (seconds: number) => {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m}:${s < 10 ? '0' : ''}${s}`;
    };

    const handlePhoneChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        let val = e.target.value.replace(/[^0-9]/g, '');
        if (val.startsWith('0')) {
            val = val.substring(1);
        }
        if (val.length > 0 && val[0] !== '5') {
            setMessage({ text: 'رقم الجوال يجب أن يبدأ بالرقم 5', type: 'error' });
            val = '';
        } else {
            setMessage({ text: '', type: '' });
        }
        if (val.length > 9) val = val.substring(0, 9);
        setPhone(val);
    };

    const sendCode = async () => {
        if (!phone) {
            setMessage({ text: 'يرجى إدخال رقم الجوال', type: 'error' });
            return;
        }
        if (!/^5[0-9]{8}$/.test(phone)) {
            setMessage({ text: 'رقم الجوال غير صحيح', type: 'error' });
            return;
        }

        setLoading(true);
        setMessage({ text: '', type: '' });
        
        // Mock sending code since user wants to skip WhatsApp for now
        setTimeout(() => {
            setLoading(false);
            setMessage({ text: 'تم إرسال رمز التحقق (محاكاة)', type: 'success' });
            setStep(2);
            setTimeLeft(60);
            setTimeout(() => {
                if (otpRefs[0].current) otpRefs[0].current.focus();
            }, 100);
        }, 1000);
    };

    const handleOtpChange = (index: number, e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value.replace(/[^0-9]/g, '');
        if (!val) {
            const newOtp = [...otp];
            newOtp[index] = '';
            setOtp(newOtp);
            return;
        }

        const newOtp = [...otp];
        newOtp[index] = val[val.length - 1]; // take the last char if multiple
        setOtp(newOtp);

        if (val && index < 3) {
            otpRefs[index + 1].current?.focus();
        }

        if (newOtp.every(x => x !== '')) {
            verifyCode(newOtp.join(''));
        }
    };

    const handleOtpKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Backspace' && !otp[index] && index > 0) {
            otpRefs[index - 1].current?.focus();
        }
    };

    const verifyCode = async (code: string) => {
        setLoading(true);
        setMessage({ text: '', type: '' });
        
        // Mock verification
        setTimeout(() => {
            setLoading(false);
            // Accept any code for now as it's dummy
            setMessage({ text: 'تم تسجيل الدخول بنجاح', type: 'success' });
            setTimeout(() => {
                router.push('/'); // Redirecting to home / dashboard
            }, 1000);
        }, 1000);
    };

    return (
        <div className={styles.mainWrapper}>
            <div className={styles.whiteLayer}></div>
            <div className={styles.loginContainer}>
                <div className={styles.logoContainer}>
                    <Image src="/logo.png" alt="الشعار" width={200} height={100} style={{ objectFit: 'contain', width: '100%', height: 'auto' }} priority />
                </div>
                
                <h1 className={styles.loginTitle}>تسجيل الدخول</h1>
                
                {message.text && (
                    <div className={`${styles.message} ${message.type === 'success' ? styles.messageSuccess : styles.messageError}`}>
                        {message.text}
                    </div>
                )}
                
                {step === 1 && (
                    <div className={styles.phoneInputContainer}>
                        <div className={styles.phoneWrapper}>
                            <span className={styles.countryCode}>+966</span>
                            <input 
                                type="tel" 
                                className={styles.phoneInput}
                                placeholder="5xxxxxxxx"
                                maxLength={9}
                                inputMode="numeric"
                                pattern="[0-9]*"
                                autoComplete="tel"
                                value={phone}
                                onChange={handlePhoneChange}
                            />
                        </div>
                        <button type="button" className={`${styles.btn} ${loading ? styles.loading : ''}`} onClick={sendCode} disabled={loading}>
                            إرسال رمز التحقق
                        </button>
                    </div>
                )}

                {step === 2 && (
                    <div className={styles.verificationContainer}>
                        <h3 className={styles.verificationTitle}>أدخل رمز التحقق</h3>
                        
                        <div className={styles.otpContainer}>
                            {otp.map((digit, index) => (
                                <input 
                                    key={index}
                                    ref={otpRefs[index]}
                                    type="tel" 
                                    className={`${styles.otpInput} ${digit ? styles.filled : ''}`}
                                    maxLength={1} 
                                    inputMode="numeric"
                                    value={digit}
                                    onChange={(e) => handleOtpChange(index, e)}
                                    onKeyDown={(e) => handleOtpKeyDown(index, e)}
                                />
                            ))}
                        </div>
                        
                        {timeLeft > 0 ? (
                            <div className={styles.timer}>{formatTime(timeLeft)}</div>
                        ) : (
                            <button type="button" className={styles.resendLink} onClick={sendCode} style={{ background: 'none', border: 'none', padding: 0, font: 'inherit' }}>
                                إعادة إرسال الرمز
                            </button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
