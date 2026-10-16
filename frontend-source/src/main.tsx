import React from 'react';
import {createRoot} from 'react-dom/client';
import Studio from './studio/Studio';
import Admin from './admin/Admin';
import './style.css';
const admin=document.querySelector<HTMLMetaElement>('meta[name="app-page"]')?.content==='admin';
const name=document.querySelector<HTMLMetaElement>('meta[name="admin-name"]')?.content||'Administrator';
createRoot(document.getElementById('root')!).render(admin?<Admin displayName={name} signOutHref="logout.php"/>:<Studio/>);
